<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Domain\Library\Normalizer;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Track;
use App\Services\MusicBrainz\MusicBrainzClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Finds the track's MusicBrainz recording and keeps its ids (recording, artist, release), ISRC and credits.
 * The artist's and album's own details are fetched once per record by EnrichArtistMusicBrainz and
 * EnrichAlbumMusicBrainz, queued from here.
 */
class EnrichTrackMusicBrainz implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    /** Relation types worth keeping as credits; others (tribute, remix of ...) aren't. */
    private const CREDIT_TYPES = [
        'producer', 'co-producer', 'executive producer', 'engineer', 'recording', 'mix', 'mastering', 'arranger',
        'conductor', 'composer', 'lyricist', 'writer', 'librettist', 'vocal', 'instrument', 'performer',
        'remixer', 'programming', 'DJ-mix',
    ];

    private const MAX_CREDITS = 40;

    public function __construct(public readonly Track $track) {}

    public function uniqueId(): string
    {
        return (string) $this->track->id;
    }

    /** MusicBrainz allows about one request per second per client; see the `musicbrainz` limiter. */
    public function middleware(): array
    {
        return [new RateLimited('musicbrainz')];
    }

    public function handle(): void
    {
        $track = $this->track->load('artist', 'album');

        if (Enrichment::isDone($track, Enrichment::MUSICBRAINZ) || !Enrichment::eligible($track)) {
            return;
        }

        $this->enrich($track, new MusicBrainzClient);

        Enrichment::markDone($track, Enrichment::MUSICBRAINZ);
    }

    private function enrich(Track $track, MusicBrainzClient $mb): void
    {
        $query = sprintf(
            'recording:"%s" AND artist:"%s"',
            str_replace('"', '\\"', trim($track->name)),
            str_replace('"', '\\"', trim($track->artist->name))
        );

        $best = collect($mb->get('recording', ['query' => $query, 'limit' => 5])['recordings'] ?? [])
            ->sortByDesc('score')
            ->first(fn ($r) => ($r['score'] ?? 0) >= 60);

        if (!$best) {
            return;
        }

        $recordingMbid = $best['id'];
        Enrichment::save($track, 'mbid', $recordingMbid, 'string', Enrichment::MUSICBRAINZ);

        $recording = $mb->get("recording/{$recordingMbid}", ['inc' => 'isrcs+artist-rels+work-rels+work-level-rels']) ?? [];
        if ($isrc = $recording['isrcs'][0] ?? null) {
            Enrichment::save($track, 'isrc', $isrc, 'string', Enrichment::MUSICBRAINZ);
        }
        if ($credits = $this->credits($recording)) {
            Enrichment::save($track, 'credits', json_encode($credits, JSON_UNESCAPED_UNICODE), 'json', Enrichment::MUSICBRAINZ);
        }

        if ($artistMbid = $best['artist-credit'][0]['artist']['id'] ?? null) {
            Enrichment::save($track->artist, 'mbid', $artistMbid, 'string', Enrichment::MUSICBRAINZ);

            if (!Enrichment::isDone($track->artist, Enrichment::MUSICBRAINZ)) {
                EnrichArtistMusicBrainz::dispatch($track->artist);
            }
        }

        // The recording appears on many releases (singles, compilations); only the one that is this album is its mbid.
        if ($track->album && ($releaseMbid = $this->releaseOf($best, $track->album->name))) {
            Enrichment::save($track->album, 'mbid', $releaseMbid, 'string', Enrichment::MUSICBRAINZ);

            if (!Enrichment::isDone($track->album, Enrichment::MUSICBRAINZ)) {
                EnrichAlbumMusicBrainz::dispatch($track->album);
            }
        }
    }

    private function releaseOf(array $recording, string $albumName): ?string
    {
        $key = Normalizer::album($albumName);

        foreach ($recording['releases'] ?? [] as $release) {
            if (isset($release['title'], $release['id']) && Normalizer::album($release['title']) === $key) {
                return $release['id'];
            }
        }

        return null;
    }

    /**
     * People credited on the recording and, through its work, on the song: `[{role, name, detail?}]`.
     *
     * @return list<array{role: string, name: string, detail?: string}>
     */
    private function credits(array $recording): array
    {
        $credits = [];
        $add = function (array $relation) use (&$credits) {
            $role = $relation['type'] ?? null;
            $name = $relation['artist']['name'] ?? null;

            if ($role === null || $name === null || !in_array($role, self::CREDIT_TYPES, true)) {
                return;
            }

            $credit = ['role' => $role, 'name' => $name];
            if ($detail = implode(', ', $relation['attributes'] ?? [])) {
                $credit['detail'] = $detail;
            }
            $credits[$role.'|'.$name.'|'.($credit['detail'] ?? '')] ??= $credit;
        };

        foreach ($recording['relations'] ?? [] as $relation) {
            $add($relation);

            foreach ($relation['work']['relations'] ?? [] as $workRelation) {
                $add($workRelation);
            }
        }

        return array_slice(array_values($credits), 0, self::MAX_CREDITS);
    }
}
