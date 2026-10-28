<?php

namespace App\Domain\Catalogue;

use App\Domain\Library\LibrarySources;
use App\Domain\Library\Normalizer;
use App\Domain\Library\SpotifyCatalog;
use App\Models\Media\AlbumAvailability;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\Track;
use Illuminate\Support\Collection;
use SpotifyWebAPI\SpotifyWebAPIException;

/**
 * Which release-groups of an artist Spotify has, whether or not the user saved them (docs/architecture/availability-layer.md,
 * "Availability lookups"). One artist-albums listing per artist is matched against all its release-groups, so the request
 * count is per artist, not per album. A match needs an equal name key; the year only sets the confidence, and a
 * candidate below the store threshold is recorded as `unavailable` (so it is not searched again for a while).
 * Albums the library already has from Spotify need no lookup: that provider is derived from their tracks.
 */
final class SpotifyAvailability
{
    public const PROVIDER = 'spotify';

    /** Confidence below which a match is not trusted. */
    public const STORE_THRESHOLD = 70;

    /** Days before an answer is asked again: found, not found, and for a wished album (so "now on Spotify" shows up). */
    public const FOUND_DAYS = 180;

    public const MISSING_DAYS = 90;

    public const WANTED_DAYS = 7;

    public function __construct(private readonly SpotifyCatalog $catalog) {}

    public function enabled(): bool
    {
        return $this->catalog->available();
    }

    /** @return Collection<int, CatalogueAlbum> the artist's release-groups whose Spotify answer is missing or too old */
    public function staleRows(Artist $artist): Collection
    {
        $rows = CatalogueAlbum::where('artist_id', $artist->id)->whereNull('ignored_at')->get();
        if ($rows->isEmpty()) {
            return $rows;
        }

        $answers = AlbumAvailability::where('provider', self::PROVIDER)
            ->where(fn ($q) => $q->whereIn('album_id', $rows->pluck('album_id')->filter()->all())->orWhereIn('catalogue_album_id', $rows->pluck('id')->all()))->get();

        return $rows->filter(function (CatalogueAlbum $row) use ($answers) {
            $answer = $answers->first(fn ($a) => ($row->album_id !== null && $a->album_id === $row->album_id) || $a->catalogue_album_id === $row->id);
            if ($answer === null) {
                return !$this->savedInSpotify($row);
            }

            $days = $row->wanted_at !== null ? self::WANTED_DAYS : ($answer->status === 'available' ? self::FOUND_DAYS : self::MISSING_DAYS);

            return $answer->checked_at->lt(now()->subDays($days));
        })->values();
    }

    /**
     * Asks Spotify once for the artist's albums and records an answer for each stale release-group.
     * Rate limits and outages throw (the job retries); an endpoint Spotify no longer answers writes nothing.
     *
     * @return int answers written
     */
    public function lookupArtist(Artist $artist): int
    {
        if (!$this->enabled() || ($rows = $this->staleRows($artist))->isEmpty()) {
            return 0;
        }

        try {
            $albums = $this->catalog->artistAlbums($artist);
        } catch (SpotifyWebAPIException $e) {
            if (in_array($e->getCode(), [400, 403, 404, 410], true)) {
                return 0;
            }
            throw $e;
        }

        $byKey = [];
        foreach ($albums as $album) {
            $byKey[Normalizer::album($album['name'])][] = $album;
        }

        foreach ($rows as $row) {
            [$match, $confidence] = $this->best($row, $byKey[$row->name_key] ?? []);
            $found = $match !== null && $confidence >= self::STORE_THRESHOLD;

            AlbumAvailability::updateOrCreate(
                $row->album_id !== null ? ['album_id' => $row->album_id, 'provider' => self::PROVIDER] : ['catalogue_album_id' => $row->id, 'provider' => self::PROVIDER],
                [
                    'status' => $found ? 'available' : 'unavailable',
                    'external_id' => $found ? 'spotify:album:'.$match['id'] : null,
                    'confidence' => $found ? $confidence : 0,
                    'match' => 'search',
                    'checked_at' => now(),
                ],
            );
        }

        return $rows->count();
    }

    /**
     * The candidate closest in year: 90 within a year, 75 otherwise (a reissue carries a later year).
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array{0: ?array<string, mixed>, 1: int}
     */
    private function best(CatalogueAlbum $row, array $candidates): array
    {
        if ($candidates === []) {
            return [null, 0];
        }

        $year = $row->first_release_year;
        usort($candidates, fn ($a, $b) => $this->gap($year, $a) <=> $this->gap($year, $b));
        $gap = $this->gap($year, $candidates[0]);

        return [$candidates[0], $gap <= 1 ? 90 : 75];
    }

    private function gap(?int $year, array $candidate): int
    {
        return $year !== null && ($candidate['year'] ?? null) !== null ? abs($year - (int) $candidate['year']) : 50;
    }

    /** Whether the library's album behind the row already has Spotify tracks (the derived provider answers). */
    private function savedInSpotify(CatalogueAlbum $row): bool
    {
        return $row->album_id !== null
            && LibrarySources::availableFrom(Track::query()->where('album_id', $row->album_id), 'spotify')->exists();
    }
}
