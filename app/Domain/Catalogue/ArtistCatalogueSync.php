<?php

namespace App\Domain\Catalogue;

use App\Domain\Library\Enrichment;
use App\Domain\Library\Normalizer;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\CatalogueArtist;
use App\Models\Play;
use App\Services\MusicBrainz\MusicBrainzClient;
use Illuminate\Support\Facades\DB;

/**
 * One artist's discography from MusicBrainz: the album and EP release-groups, upserted by (artist, release-group),
 * then linked to the library's albums. Idempotent; a rate limit or outage throws before anything is written, so a
 * retry starts clean. Artists without a MusicBrainz id are recorded as `no_mbid` (the id discovery is a separate step).
 */
final class ArtistCatalogueSync
{
    /** Days until an artist is synced again: played or favourite artists monthly, the rest quarterly (plus up to 5 days of jitter). */
    public const ACTIVE_DAYS = 30;

    public const IDLE_DAYS = 90;

    public function __construct(private readonly MusicBrainzClient $mb = new MusicBrainzClient) {}

    /** @return string the resulting status: ok, no_mbid, unverified or not_found */
    public function sync(Artist $artist): string
    {
        $mbid = Enrichment::mbid($artist);
        $groups = null;

        if ($mbid === null) {
            [$mbid, $groups] = $this->discover($artist);
            if ($mbid === null) {
                return $this->finish($artist, null, $this->lookedUp ? 'unverified' : 'no_mbid', null, self::IDLE_DAYS);
            }
        } else {
            $groups = $this->mb->releaseGroups($mbid);
        }

        if ($groups === null) {
            return $this->finish($artist, $mbid, 'not_found', null, self::IDLE_DAYS);
        }

        $started = now();
        DB::transaction(function () use ($artist, $mbid, $groups, $started) {
            $previous = CatalogueArtist::find($artist->id);
            if ($previous?->mbid !== null && $previous->mbid !== $mbid) {
                $this->dropUnreferenced($artist);
            }

            foreach ($groups as $group) {
                CatalogueAlbum::updateOrCreate(
                    ['artist_id' => $artist->id, 'release_group_mbid' => $group['id']],
                    [
                        'title' => $group['title'],
                        'type' => $group['type'],
                        'first_release_date' => $group['date'],
                        'first_release_year' => $group['date'] !== null && preg_match('/^\d{4}/', $group['date']) ? (int) substr($group['date'], 0, 4) : null,
                        'disambiguation' => $group['disambiguation'],
                        'seen_at' => $started,
                    ],
                );
            }

            // A release-group MusicBrainz no longer lists goes, unless someone cares about it.
            $seen = array_flip(array_column($groups, 'id'));
            $gone = CatalogueAlbum::where('artist_id', $artist->id)->whereNull('wanted_at')->whereNull('ignored_at')->whereNull('album_id')
                ->pluck('release_group_mbid', 'id')->reject(fn ($mbid) => isset($seen[$mbid]))->keys();
            foreach ($gone->chunk(500) as $ids) {
                CatalogueAlbum::whereIn('id', $ids)->delete();
            }
        });

        CatalogueLinker::linkArtist($artist);

        return $this->finish($artist, $mbid, 'ok', count($groups), $this->isActive($artist) ? self::ACTIVE_DAYS : self::IDLE_DAYS);
    }

    /** Whether discover() found a candidate it then could not verify (else the artist is simply unknown to MusicBrainz). */
    private bool $lookedUp = false;

    /**
     * An artist without a MusicBrainz id: search by name and accept a candidate only when its name key equals ours,
     * its score is at least 95 and it is the only such candidate, then **verify** it: one of the library's albums by
     * this artist must equal one of its release-groups (so a namesake is never taken). Only a verified id is saved.
     *
     * @return array{0: ?string, 1: ?list<array<string, mixed>>} the id and the release-groups already fetched for it
     */
    private function discover(Artist $artist): array
    {
        $this->lookedUp = false;
        $key = Normalizer::artist($artist->name);
        if ($key === '' || strcasecmp($artist->name, 'Unknown Artist') === 0) {
            return [null, null];
        }

        $found = $this->mb->searchArtists($artist->name);
        $candidates = array_values(array_filter($found, fn ($c) => ($c['score'] ?? 0) >= 95 && Normalizer::artist($c['name'] ?? '') === $key));
        if (count($candidates) !== 1) {
            $this->lookedUp = count($candidates) > 1;

            return [null, null];
        }

        $this->lookedUp = true;
        $mbid = $candidates[0]['id'];
        $groups = $this->mb->releaseGroups($mbid);
        $owned = $artist->albums()->pluck('name_key')->filter()->all();
        if ($groups === null || !array_intersect($owned, array_map(fn ($g) => Normalizer::album($g['title']), $groups))) {
            return [null, null];
        }

        Enrichment::save($artist, 'mbid', $mbid, 'string', Enrichment::MUSICBRAINZ);

        return [$mbid, $groups];
    }

    /** Records a failed run (called by the job when it gives up). */
    public static function markError(Artist $artist): void
    {
        $row = CatalogueArtist::firstOrNew(['artist_id' => $artist->id]);
        $row->forceFill(['status' => 'error', 'attempts' => ($row->attempts ?? 0) + 1, 'next_sync_at' => now()->addDay()])->save();
    }

    private function finish(Artist $artist, ?string $mbid, string $status, ?int $count, int $days): string
    {
        CatalogueArtist::updateOrCreate(['artist_id' => $artist->id], [
            'mbid' => $mbid,
            'status' => $status,
            'release_group_count' => $count,
            'synced_at' => now(),
            'next_sync_at' => now()->addDays($days)->addDays(random_int(0, 5)),
            'attempts' => 0,
        ]);

        return $status;
    }

    /** Played in the last 90 days or a favourite. */
    private function isActive(Artist $artist): bool
    {
        return $artist->favorited_at !== null
            || Play::where('played_at', '>=', now()->subDays(90))
                ->whereIn('track_id', fn ($q) => $q->select('id')->from('tracks')->where('artist_id', $artist->id))->exists();
    }

    /** The artist's mbid changed: its old release-groups are wrong, except the ones with a wish, an ignore or a link. */
    private function dropUnreferenced(Artist $artist): void
    {
        CatalogueAlbum::where('artist_id', $artist->id)->whereNull('wanted_at')->whereNull('ignored_at')->whereNull('album_id')->delete();
    }
}
