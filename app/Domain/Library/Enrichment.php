<?php

namespace App\Domain\Library;

use App\Jobs\EnrichAlbumAudioDb;
use App\Jobs\EnrichAlbumDiscogs;
use App\Jobs\EnrichAlbumLastfm;
use App\Jobs\EnrichAlbumMusicBrainz;
use App\Jobs\EnrichArtistAudioDb;
use App\Jobs\EnrichArtistLastfm;
use App\Jobs\EnrichArtistMusicBrainz;
use App\Jobs\EnrichTrackLastfm;
use App\Jobs\EnrichTrackLyrics;
use App\Jobs\EnrichTrackMusicBrainz;
use App\Jobs\EnrichTrackSpotify;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Services\Discogs\DiscogsClient;
use App\Services\Lastfm\LastfmInfoClient;
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

    public const AUDIODB = 'audiodb';

    public const DISCOGS = 'discogs';

    /** Tracks queued by a library scan, so a first scan of a big library doesn't flood the queue. */
    public const SCAN_BATCH = 100;

    private const LEGACY_SOURCE = 'system';

    private const UNKNOWN_ARTIST = 'Unknown Artist';

    private const UNKNOWN_ALBUM = 'Unknown Album';

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

        // Every source's genres end up in the canonical genres, wherever they are written from.
        if ($key === 'genres' && method_exists($model, 'genreRelation')) {
            GenreSync::sync($model, json_decode($value, true) ?: [], $source);
        }
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

    /** Last.fm's `listeners` and `playcount` (artist stats, or the track/album info itself). */
    public static function saveStats(Model $model, array $info): void
    {
        foreach (['listeners', 'playcount'] as $stat) {
            if (isset($info[$stat]) && is_numeric($info[$stat])) {
                self::save($model, 'lastfm_'.$stat, (string) (int) $info[$stat], 'int', self::LASTFM);
            }
        }
    }

    /**
     * Free-form tags (Last.fm), cleaned to the names GenreNormalizer accepts and kept as `tags`. They are no
     * genres, so they only stand in as the record's genres while it has none.
     *
     * @param  list<string>  $tags  most popular first
     */
    public static function saveTags(Model $model, array $tags, string $source): void
    {
        $names = array_slice(array_column(GenreNormalizer::normalize($tags), 1), 0, 8);

        if ($names) {
            self::save($model, 'tags', json_encode($names, JSON_UNESCAPED_UNICODE), 'json', $source);
            self::fallbackGenres($model, array_slice($names, 0, 3), $source);
        }
    }

    /** Genres from a weak source: used only while the record has no genres at all. */
    public static function fallbackGenres(Model $model, array $genres, string $source): void
    {
        if (!$model->genreRelation()->exists()) {
            GenreSync::sync($model, array_filter($genres), $source);
        }
    }

    /** Albums without a real name or artist can't be matched against any service. */
    public static function albumEligible(Album $album): bool
    {
        $name = trim($album->name);
        $artist = trim($album->artist?->name ?? '');

        return $name !== '' && strcasecmp($name, self::UNKNOWN_ALBUM) !== 0
            && $artist !== '' && strcasecmp($artist, self::UNKNOWN_ARTIST) !== 0;
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

        $queued += self::queueArtist($track->artist);

        if ($track->album) {
            $queued += self::queueAlbum($track->album->setRelation('artist', $track->artist));
        }

        return $queued;
    }

    /** Queues the artist-level jobs whose data is available and not yet fetched; returns how many. */
    public static function queueArtist(Artist $artist): int
    {
        $hasMbid = self::hasMeta($artist, 'mbid', self::MUSICBRAINZ);

        return self::dispatchUndone($artist, array_filter([
            self::MUSICBRAINZ => $hasMbid ? new EnrichArtistMusicBrainz($artist) : null,
            self::AUDIODB => $hasMbid ? new EnrichArtistAudioDb($artist) : null,
            self::LASTFM => LastfmInfoClient::enabled() ? new EnrichArtistLastfm($artist) : null,
        ]));
    }

    /** Queues the album-level jobs whose data is available and not yet fetched; returns how many. */
    public static function queueAlbum(Album $album): int
    {
        $eligible = self::albumEligible($album);

        return self::dispatchUndone($album, array_filter([
            self::MUSICBRAINZ => self::hasMeta($album, 'mbid', self::MUSICBRAINZ) ? new EnrichAlbumMusicBrainz($album) : null,
            self::AUDIODB => self::hasMeta($album, 'release_group_mbid') ? new EnrichAlbumAudioDb($album) : null,
            self::LASTFM => $eligible && LastfmInfoClient::enabled() ? new EnrichAlbumLastfm($album) : null,
            self::DISCOGS => $eligible && DiscogsClient::enabled() ? new EnrichAlbumDiscogs($album) : null,
        ]));
    }

    /** @param  array<string, object>  $jobs  by source */
    private static function dispatchUndone(Model $model, array $jobs): int
    {
        $queued = 0;

        foreach ($jobs as $source => $job) {
            if (!self::isDone($model, $source)) {
                dispatch($job);
                $queued++;
            }
        }

        return $queued;
    }

    private static function hasMeta(Model $model, string $key, ?string $source = null): bool
    {
        return $model->metadata()->where('key', $key)->when($source, fn ($q) => $q->where('source', $source))->exists();
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

    /**
     * Queues the artist and album jobs that are possible (MusicBrainz and AudioDB need the ids the track job
     * found) but not done, up to $limit records of each kind, newest first; returns how many jobs were queued.
     */
    public static function queueEntities(int $limit): int
    {
        $queued = 0;

        self::artistBacklog()->latest('id')->limit($limit)->get()->each(function (Artist $artist) use (&$queued) {
            $queued += self::queueArtist($artist);
        });
        self::albumBacklog()->with('artist')->latest('id')->limit($limit)->get()->each(function (Album $album) use (&$queued) {
            $queued += self::queueAlbum($album);
        });

        return $queued;
    }

    /** @return Builder<Artist> artists with at least one job still to do */
    public static function artistBacklog(): Builder
    {
        $notDone = fn (string $source) => fn (Builder $q) => $q->whereDoesntHave('metadata', fn ($m) => $m->where('key', 'enriched_at')->where('source', $source));
        $hasMbid = fn (Builder $q) => $q->whereHas('metadata', fn ($m) => $m->where('key', 'mbid')->where('source', self::MUSICBRAINZ));

        return Artist::query()
            ->whereRaw('lower(name) != ?', [strtolower(self::UNKNOWN_ARTIST)])
            ->where(function (Builder $q) use ($notDone, $hasMbid) {
                $q->where(fn (Builder $a) => $a->where($hasMbid)->where(fn (Builder $b) => $b->where($notDone(self::MUSICBRAINZ))->orWhere($notDone(self::AUDIODB))));

                if (LastfmInfoClient::enabled()) {
                    $q->orWhere($notDone(self::LASTFM));
                }
            });
    }

    /** @return Builder<Album> albums with at least one job still to do */
    public static function albumBacklog(): Builder
    {
        $notDone = fn (string $source) => fn (Builder $q) => $q->whereDoesntHave('metadata', fn ($m) => $m->where('key', 'enriched_at')->where('source', $source));

        return Album::query()
            ->whereRaw('lower(name) != ?', [strtolower(self::UNKNOWN_ALBUM)])
            ->whereHas('artist', fn ($a) => $a->whereRaw('lower(name) != ?', [strtolower(self::UNKNOWN_ARTIST)]))
            ->where(function (Builder $q) use ($notDone) {
                $q->where(fn (Builder $a) => $a
                    ->whereHas('metadata', fn ($m) => $m->where('key', 'mbid')->where('source', self::MUSICBRAINZ))
                    ->where($notDone(self::MUSICBRAINZ)))
                    ->orWhere(fn (Builder $a) => $a
                        ->whereHas('metadata', fn ($m) => $m->where('key', 'release_group_mbid'))
                        ->where($notDone(self::AUDIODB)));

                if (LastfmInfoClient::enabled()) {
                    $q->orWhere($notDone(self::LASTFM));
                }
                if (DiscogsClient::enabled()) {
                    $q->orWhere($notDone(self::DISCOGS));
                }
            });
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
                if (LastfmInfoClient::enabled()) {
                    $q->orWhere($missing(self::LASTFM));
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

        if (LastfmInfoClient::enabled()) {
            $jobs[self::LASTFM] = new EnrichTrackLastfm($track);
        }

        return $jobs;
    }

    private static function spotifyConnected(): bool
    {
        return app(SpotifyTokenService::class)->isConnected();
    }
}
