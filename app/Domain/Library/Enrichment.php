<?php

namespace App\Domain\Library;

use App\Jobs\EnrichArtistLastfm;
use App\Jobs\EnrichTrackLyrics;
use App\Jobs\EnrichTrackMusicBrainz;
use App\Jobs\EnrichTrackSpotify;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Services\SpotifyTokenService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-source enrichment bookkeeping for the metadata around tracks.
 *
 * Every source (musicbrainz, lrclib, spotify, lastfm) is its own queued job and records its own
 * `enriched_at` marker once it has *finished* (a "no match" counts, a failure doesn't), so a failing
 * source is retried without redoing the others. Tracks marked by the old single-job pipeline
 * (`enriched_at` from source `system`) count as done for every source.
 */
class Enrichment
{
    public const MUSICBRAINZ = 'musicbrainz';

    public const LRCLIB = 'lrclib';

    public const SPOTIFY = 'spotify';

    public const LASTFM = 'lastfm';

    /** Tracks queued by a library scan, so a first scan of a big library doesn't flood the queue. */
    public const SCAN_BATCH = 100;

    private const LEGACY_SOURCE = 'system';

    private const UNKNOWN_ARTIST = 'Unknown Artist';

    public static function save(Model $model, string $key, string $value, string $type, string $source): void
    {
        Metadata::query()->updateOrCreate(
            [
                'metadatable_type' => $model->getMorphClass(),
                'metadatable_id' => $model->id,
                'key' => $key,
                'source' => $source,
            ],
            [
                'value' => $value,
                'type' => $type,
                'parent_id' => null,
            ]
        );
    }

    public static function markDone(Model $model, string $source): void
    {
        self::save($model, 'enriched_at', now()->toIso8601String(), 'string', $source);
    }

    public static function isDone(Model $model, string $source): bool
    {
        return $model->metadata()
            ->where('key', 'enriched_at')
            ->whereIn('source', [$source, self::LEGACY_SOURCE])
            ->exists();
    }

    /** Tracks without a real artist can't be matched against any service. */
    public static function eligible(Track $track): bool
    {
        $name = trim($track->artist?->name ?? '');

        return trim($track->name) !== '' && $name !== '' && strcasecmp($name, self::UNKNOWN_ARTIST) !== 0;
    }

    /** Queues the enrichment jobs a track is still missing; returns how many. */
    public static function queue(Track $track): int
    {
        if (!self::eligible($track)) {
            return 0;
        }

        $queued = 0;

        foreach (self::trackJobs($track) as $source => $job) {
            if (!self::isDone($track, $source)) {
                dispatch($job);
                $queued++;
            }
        }

        $artist = $track->artist;
        if (config('lastfm.api_key') && !$artist->metadata()->where('source', self::LASTFM)->exists()) {
            EnrichArtistLastfm::dispatch($artist);
            $queued++;
        }

        return $queued;
    }

    /**
     * Queues enrichment for up to $limit tracks that are still missing a source, newest first.
     * Tracks already queued are skipped by the jobs' uniqueness lock, so this is safe to repeat.
     */
    public static function queueBacklog(int $limit): int
    {
        $tracks = self::backlog()->with('artist')->latest('id')->limit($limit)->get();

        foreach ($tracks as $track) {
            self::queue($track);
        }

        return $tracks->count();
    }

    /** @return Builder<Track> */
    public static function backlog(): Builder
    {
        $missing = fn (string $source) => fn (Builder $q) => $q->whereDoesntHave(
            'metadata',
            fn ($m) => $m->where('key', 'enriched_at')->whereIn('source', [$source, self::LEGACY_SOURCE])
        );

        return Track::query()
            ->whereHas('artist', fn ($a) => $a->whereRaw('lower(name) != ?', [strtolower(self::UNKNOWN_ARTIST)]))
            ->where(function (Builder $q) use ($missing) {
                $q->where($missing(self::MUSICBRAINZ))->orWhere($missing(self::LRCLIB));

                if (self::spotifyConnected()) {
                    $q->orWhere(fn (Builder $s) => $s->where('source', 'spotify')->where($missing(self::SPOTIFY)));
                }
            });
    }

    /** @return array<string, object> */
    private static function trackJobs(Track $track): array
    {
        $jobs = [
            self::MUSICBRAINZ => new EnrichTrackMusicBrainz($track),
            self::LRCLIB => new EnrichTrackLyrics($track),
        ];

        if ($track->source === 'spotify' && self::spotifyConnected()) {
            $jobs[self::SPOTIFY] = new EnrichTrackSpotify($track);
        }

        return $jobs;
    }

    private static function spotifyConnected(): bool
    {
        return app(SpotifyTokenService::class)->isConnected();
    }
}
