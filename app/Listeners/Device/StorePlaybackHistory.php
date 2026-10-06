<?php

namespace App\Listeners\Device;

use App\Domain\Library\Enrichment;
use App\Domain\Library\LibraryIdentity;
use App\Domain\Library\LibrarySettings;
use App\Domain\Library\Normalizer;
use App\Events\Device\NowPlayingUpdated;
use App\Integrations\Contracts\RadioControlInterface;
use App\Jobs\ImportSpotifyAlbum;
use App\Jobs\ScrobbleToLastfm;
use App\Jobs\SendNowPlayingToLastfm;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\RadioStation;
use App\Models\RadioStationMeta;
use App\Services\SpotifyTokenService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

class StorePlaybackHistory implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        if (!$event instanceof NowPlayingUpdated) {
            return;
        }

        $deviceId = trim((string) $event->deviceId);
        if ($deviceId === '') {
            return;
        }

        $nowPlaying = $event->nowPlaying;
        $npTrack = $nowPlaying->track;

        // Build signature to detect new content (prevent duplicates from repeated events)
        $radio = $nowPlaying->radio;
        $source = $nowPlaying->source;

        if ($radio !== null && ($npTrack === null || trim((string) $npTrack->artist?->name) === '')) {
            // --- Radio play ---
            $signatureParts = [
                'type' => 'radio',
                'sourceType' => $event->sourceType,
                'radioName' => $radio->name,
                'genre' => $radio->genre,
            ];

            $signature = hash('sha256', json_encode($signatureParts, JSON_THROW_ON_ERROR));
            $cacheKey = "device:{$deviceId}:last_playback_signature";
            $previous = Cache::get($cacheKey);

            if (is_string($previous) && hash_equals($previous, $signature)) {
                return;
            }

            $this->closePreviousPlay((int) $deviceId);
            Cache::put($cacheKey, $signature, now()->addDay());

            $radioStation = $this->resolveRadioStation($radio->name, $radio->id, (int) $deviceId);

            Play::create([
                'device_id' => (int) $deviceId,
                'track_id' => null,
                'source_type' => 'radio',
                'radio_name' => $radio->name,
                'radio_station_id' => $radioStation?->id,
                'played_at' => now(),
            ]);

            return;
        }

        if ($npTrack === null) {
            // --- Source/line-in play (no track, no radio) ---
            $sourceName = $source?->name ?? $source?->connector ?? null;
            if ($sourceName === null || trim($sourceName) === '') {
                return;
            }

            // Music streaming services fire a SOURCE notification before NOW_PLAYING_STORED_MUSIC.
            // Skip the premature source record; the track play will be stored when the track arrives.
            $knownStreamingTypes = ['spotify', 'deezer', 'tidal', 'qobuz'];
            if (in_array(strtolower($source?->sourceType ?? ''), $knownStreamingTypes, true)) {
                return;
            }

            $signatureParts = [
                'type' => 'source',
                'sourceType' => $event->sourceType,
                'sourceName' => $sourceName,
                'connector' => $source?->connector,
            ];

            $signature = hash('sha256', json_encode($signatureParts, JSON_THROW_ON_ERROR));
            $cacheKey = "device:{$deviceId}:last_playback_signature";
            $previous = Cache::get($cacheKey);

            if (is_string($previous) && hash_equals($previous, $signature)) {
                return;
            }

            $this->closePreviousPlay((int) $deviceId);
            Cache::put($cacheKey, $signature, now()->addDay());

            Play::create([
                'device_id' => (int) $deviceId,
                'track_id' => null,
                'source_type' => $source?->sourceType ?? $event->sourceType ?? 'source',
                'source_name' => trim($sourceName),
                'played_at' => now(),
            ]);

            return;
        }

        // --- Track play ---

        // Track MUST have an artist_id, so if we can't resolve an artist name, we skip.
        $artistName = $npTrack->artist?->name;
        $artistName = is_string($artistName) ? trim($artistName) : '';

        if ($artistName === '') {
            return;
        }

        // Build a stable signature so repeated "now playing" updates don't create duplicates.
        // Only the normalized artist + track: a speaker playing Spotify is reported by both
        // its own listener and the Spotify listener, with different ids, sources, durations
        // and album spellings, and those alternating reports must count as one play.
        $signatureParts = [
            'type' => 'track',
            'artist' => Normalizer::artist($artistName),
            'track' => Normalizer::track($npTrack->name),
        ];

        $signature = hash('sha256', json_encode($signatureParts, JSON_THROW_ON_ERROR));

        $cacheKey = "device:{$deviceId}:last_playback_signature";
        $previous = Cache::get($cacheKey);

        if (is_string($previous) && hash_equals($previous, $signature)) {
            return;
        }

        $this->closePreviousPlay((int) $deviceId);
        Cache::put($cacheKey, $signature, now()->addDay());

        // --- Persist / upsert normalized media entities ---

        // One record per artist/album/track whatever the source (LibraryIdentity),
        // so a play of a DLNA-scanned or Spotify-imported track counts for it.
        $albumName = $nowPlaying->album?->name;
        $albumName = is_string($albumName) ? trim($albumName) : '';

        // Unless played tracks are added to the library, only a track already in it is
        // matched; any other is logged as text (names, cover) and leaves the library alone.
        $matched = null;
        if (!LibrarySettings::addPlayedTracks()) {
            $playedName = is_string($npTrack->name) ? trim($npTrack->name) : '';
            $matched = $playedName === '' ? null : LibraryIdentity::lookupTrack(
                $artistName,
                $albumName,
                $playedName,
                $npTrack->id ?? $this->spotifyIdFromMeta($npTrack->meta ?? [], $npTrack->source),
                $npTrack->source ?? $event->sourceType ?? null,
                $npTrack->duration,
            );

            if ($matched === null) {
                $this->storeUnmatchedPlay($event, $artistName, $albumName, $playedName);

                return;
            }
        }

        $artistSource = $npTrack->artist?->source
            ?? $npTrack->source
            ?? null;

        $artist = $matched?->artist ?? LibraryIdentity::artist($artistName, $artistSource);

        // Album is OPTIONAL (tracks.album_id nullable)
        $albumId = null;
        $album = null;

        if ($matched !== null) {
            $album = $matched->album;
            $albumId = $album?->id;
        } elseif ($albumName !== '') {
            $albumSource = $nowPlaying->album?->source ?? $npTrack->source ?? null;
            $albumImages = $this->normalizeImages($nowPlaying->album?->images ?? []);

            $album = LibraryIdentity::album($artist, $albumName, $albumSource, [
                'images' => $albumImages ?: null,
                'released_at' => $nowPlaying->album?->released_at ?? null,
            ]);

            $albumId = $album->id;
        }

        $trackSource = $npTrack->source ?? $event->sourceType ?? null;
        $trackName = $npTrack->name ?? '';

        $trackName = is_string($trackName) ? trim($trackName) : '';
        if ($trackName === '') {
            return;
        }

        $track = LibraryIdentity::track(
            $artist,
            $album,
            $trackName,
            $npTrack->id ?? $this->spotifyIdFromMeta($npTrack->meta ?? [], $npTrack->source),
            $trackSource,
            [
                'duration' => $npTrack->duration,
                'images' => $this->normalizeImages($npTrack->images ?? []) ?: null,
            ],
        );

        if ($track->wasRecentlyCreated) {
            Enrichment::queue($track);
        }

        // --- Resolve radio station when track is playing via a radio source ---
        $radioStation = null;
        if ($radio !== null) {
            $radioStation = $this->resolveRadioStation($radio->name, $radio->id, (int) $deviceId);
        }

        // --- Record the play event ---
        Play::create([
            'device_id' => (int) $deviceId,
            'track_id' => $track->id,
            'source_type' => $npTrack->source ?? $nowPlaying->platform ?? $event->sourceType ?? 'music',
            'radio_name' => $radio?->name,
            'radio_station_id' => $radioStation?->id,
            'played_at' => now(),
        ]);

        SendNowPlayingToLastfm::dispatch($track);

        // --- Store metadata from the Track object (key/value) ---
        // Drivers send a list of key/value arrays (Spotify) or one flat key/value array (B&O ASE).
        $metaEntries = $npTrack->meta ?? [];
        foreach (array_is_list($metaEntries) ? $metaEntries : [$metaEntries] as $meta) {
            if (is_array($meta)) {
                $this->storeTrackMetadata($track, $meta, $trackSource);
            }
        }

        // --- Enrich Spotify tracks with API metadata (release date, etc.) ---
        if ($npTrack->source === 'spotify' && $albumId !== null) {
            $this->enrichSpotifyAlbum($album, $npTrack->meta ?? []);
        }

        // --- Add the rest of the album to the library ---
        $spotifyTrackUri = $track->external_id && str_starts_with($track->external_id, 'spotify:track:')
            ? $track->external_id
            : $this->spotifyIdFromMeta($npTrack->meta ?? [], $npTrack->source);

        if ($album !== null && $spotifyTrackUri !== null) {
            ImportSpotifyAlbum::dispatch($album, substr($spotifyTrackUri, strlen('spotify:track:')));
        }
    }

    /** A track play for a track that isn't in the library: kept as text, with the cover for the artwork proxy. */
    private function storeUnmatchedPlay(NowPlayingUpdated $event, string $artistName, string $albumName, string $trackName): void
    {
        if ($trackName === '') {
            return;
        }

        $nowPlaying = $event->nowPlaying;
        $npTrack = $nowPlaying->track;

        $image = $this->normalizeImages($npTrack->images ?? [])[0]['url']
            ?? $this->normalizeImages($nowPlaying->album?->images ?? [])[0]['url']
            ?? null;

        $play = Play::create([
            'device_id' => (int) $event->deviceId,
            'track_id' => null,
            'track_name' => $trackName,
            'artist_name' => $artistName,
            'album_name' => $albumName !== '' ? $albumName : null,
            'image_url' => $image,
            'duration' => $npTrack->duration,
            'source_type' => $npTrack->source ?? $nowPlaying->platform ?? $event->sourceType ?? 'music',
            'played_at' => now(),
        ]);

        SendNowPlayingToLastfm::dispatch($play);
    }

    /**
     * Listeners report images as plain URL strings, the library stores (and the views read)
     * them as `{url}` entries like the Spotify importer does.
     *
     * @param  array<int, mixed>  $images
     * @return array<int, array<string, mixed>>
     */
    private function normalizeImages(array $images): array
    {
        $normalized = [];

        foreach ($images as $image) {
            if (is_string($image) && trim($image) !== '') {
                $normalized[] = ['url' => trim($image)];
            } elseif (is_array($image) && !empty($image['url'])) {
                $normalized[] = $image;
            }
        }

        return $normalized;
    }

    /** A speaker playing Spotify (B&O ASE) reports the URI only in its meta. */
    private function spotifyIdFromMeta(array $meta, ?string $source): ?string
    {
        if ($source !== 'spotify') {
            return null;
        }

        foreach ($meta as $key => $entry) {
            $value = is_array($entry) ? ($entry['spotifyId'] ?? null) : ($key === 'spotifyId' ? $entry : null);

            if (is_string($value) && str_starts_with($value, 'spotify:track:')) {
                return $value;
            }
        }

        return null;
    }

    private function enrichSpotifyAlbum(?Album $album, array $meta): void
    {
        if ($album === null || $album->released_at !== null) {
            return;
        }

        // Extract Spotify track ID from meta
        $spotifyId = null;
        foreach ($meta as $entry) {
            if (is_array($entry) && isset($entry['spotifyId'])) {
                $spotifyId = $entry['spotifyId'];
                break;
            }
        }

        if ($spotifyId === null) {
            return;
        }

        // Parse track ID from URI (spotify:track:xxxx)
        $parts = explode(':', $spotifyId);
        $trackId = end($parts);

        if ($trackId === '' || $trackId === false) {
            return;
        }

        try {
            $tokenService = app(SpotifyTokenService::class);

            if (!$tokenService->isConnected()) {
                return;
            }

            $api = $tokenService->makeApiClient();
            $spotifyTrack = $api->getTrack($trackId);

            $releaseDate = $spotifyTrack['album']['release_date'] ?? null;

            if ($releaseDate !== null) {
                $album->update(['released_at' => $releaseDate]);
            }
        } catch (\Throwable) {
            // Enrichment is best-effort — don't fail the listener
        }
    }

    private function resolveRadioStation(?string $name, ?string $platformId, int $deviceId): ?RadioStation
    {
        // Determine which platform this device uses for radio
        $platform = null;
        try {
            $device = Device::find($deviceId);
            $driver = $device?->driver;
            if ($driver instanceof RadioControlInterface) {
                $platform = $driver->radioPlatform();
            }
        } catch (\Throwable) {
            // Driver not loadable — skip identifier linking
        }

        // Try to find an existing station by platform key first (most precise)
        if ($platform !== null && $platformId !== null) {
            $station = RadioStationMeta::where('key', $platform)
                ->where('value', $platformId)
                ->first()
                ?->station;

            if ($station !== null) {
                return $station;
            }
        }

        // Fall back to matching by name
        $station = $name !== null ? RadioStation::where('name', $name)->first() : null;

        if ($station !== null) {
            // Back-fill missing meta so future plays link by ID
            if ($platform !== null && $platformId !== null && $station->getMeta($platform) === null) {
                $station->setMeta($platform, $platformId);
            }

            return $station;
        }

        // Auto-create a station entry when we have a platform key to anchor it
        if ($name !== null && $platform !== null && $platformId !== null) {
            $station = RadioStation::create(['name' => $name]);
            $station->setMeta($platform, $platformId);

            return $station;
        }

        return null;
    }

    private function closePreviousPlay(int $deviceId): void
    {
        $play = Play::query()
            ->where('device_id', $deviceId)
            ->whereNull('ended_at')
            ->latest('played_at')
            ->first();

        if ($play === null) {
            return;
        }

        $endedAt = now();
        $skipped = $play->played_at->diffInSeconds($endedAt) < 30;

        $play->update(['ended_at' => $endedAt, 'skipped' => $skipped]);

        if ($play->isTrackPlay()) {
            ScrobbleToLastfm::dispatch($play);
        }
    }

    /**
     * @param  array<int|string, mixed>  $meta
     */
    private function storeTrackMetadata(Track $track, array $meta, ?string $source): void
    {
        if ($meta === []) {
            return;
        }

        foreach ($meta as $key => $value) {
            $metaKey = is_string($key) && $key !== '' ? $key : null;
            if ($metaKey === null) {
                // If meta is a list (not key/value), store it as a single json blob
                $metaKey = 'meta';
                $value = $meta;
            }

            $type = $this->inferMetadataType($value);

            Metadata::query()->updateOrCreate(
                [
                    'metadatable_type' => $track->getMorphClass(),
                    'metadatable_id' => $track->id,
                    'key' => $metaKey,
                    'source' => $source,
                ],
                [
                    'value' => $this->stringifyMetadataValue($value),
                    'type' => $type,
                    'parent_id' => null,
                ]
            );

            // If we just stored the whole list as json, stop.
            if ($metaKey === 'meta' && $value === $meta) {
                return;
            }
        }
    }

    private function inferMetadataType(mixed $value): ?string
    {
        if (is_int($value)) {
            return 'int';
        }

        if (is_float($value)) {
            return 'float';
        }

        if (is_bool($value)) {
            return 'bool';
        }

        if (is_array($value) || is_object($value)) {
            return 'json';
        }

        if (is_string($value)) {
            $v = trim($value);
            if ($v === '') {
                return 'string';
            }

            if (filter_var($v, FILTER_VALIDATE_URL)) {
                return 'url';
            }

            return 'string';
        }

        return null;
    }

    private function stringifyMetadataValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
