<?php

namespace App\Listeners\Device;

use App\Events\Device\NowPlayingUpdated;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\Play;
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
                'type'       => 'radio',
                'sourceType' => $event->sourceType,
                'radioName'  => $radio->name,
                'genre'      => $radio->genre,
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
                'device_id'   => (int) $deviceId,
                'track_id'    => null,
                'source_type' => 'radio',
                'radio_name'  => $radio->name,
                'played_at'   => now(),
            ]);

            return;
        }

        if ($npTrack === null) {
            // --- Source/line-in play (no track, no radio) ---
            $sourceName = $source?->name ?? $source?->connector ?? null;
            if ($sourceName === null || trim($sourceName) === '') {
                return;
            }

            $signatureParts = [
                'type'        => 'source',
                'sourceType'  => $event->sourceType,
                'sourceName'  => $sourceName,
                'connector'   => $source?->connector,
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
                'device_id'   => (int) $deviceId,
                'track_id'    => null,
                'source_type' => $source?->sourceType ?? $event->sourceType ?? 'source',
                'source_name' => trim($sourceName),
                'played_at'   => now(),
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

        // Build a stable signature so repeated "now playing" updates don't create duplicates
        $signatureParts = [
            'type'        => 'track',
            'sourceType'  => $event->sourceType,
            'trackId'     => $npTrack->id,
            'trackName'   => $npTrack->name,
            'trackSource' => $npTrack->source,
            'artistName'  => $artistName,
            'albumName'   => $nowPlaying->album?->name,
            'duration'    => $npTrack->duration,
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

        $artistSource = $npTrack->artist?->source
            ?? $npTrack->source
            ?? null;

        $artist = Artist::query()->firstOrCreate(
            [
                'name' => $artistName,
                'source' => $artistSource,
            ],
            [
                'name' => $artistName,
                'source' => $artistSource,
            ]
        );

        // Album is OPTIONAL (tracks.album_id nullable)
        $albumId = null;

        $albumName = $nowPlaying->album?->name;
        $albumName = is_string($albumName) ? trim($albumName) : '';

        if ($albumName !== '') {
            $albumSource = $nowPlaying->album?->source ?? $npTrack->source ?? null;
            $albumImages = $nowPlaying->album?->images ?? [];

            $album = Album::query()->firstOrCreate(
                [
                    'artist_id' => $artist->id,
                    'name' => $albumName,
                    'source' => $albumSource,
                ],
                [
                    'artist_id' => $artist->id,
                    'name' => $albumName,
                    'source' => $albumSource,
                    'images' => $albumImages ?: null,
                    'released_at' => $nowPlaying->album?->released_at ?? null,
                ]
            );

            $albumId = $album->id;
        }

        $trackExternalId = $npTrack->id;
        $trackSource = $npTrack->source ?? $event->sourceType ?? null;
        $trackName = $npTrack->name ?? '';

        $trackName = is_string($trackName) ? trim($trackName) : '';
        if ($trackName === '') {
            return;
        }

        $track = Track::query()->updateOrCreate(
            [
                'name' => $trackName,
                'artist_id' => $artist->id,
                'source' => $trackSource,
            ],
            [
                'artist_id' => $artist->id,
                'album_id' => $albumId,
                'external_id' => $trackExternalId,
                'name' => $trackName,
                'duration' => $npTrack->duration,
                'source' => $trackSource,
                'images' => ($npTrack->images ?? []) ?: null,
            ]
        );

        // --- Record the play event ---
        Play::create([
            'device_id'   => (int) $deviceId,
            'track_id'    => $track->id,
            'source_type' => $npTrack->source ?? $nowPlaying->platform ?? $event->sourceType ?? 'music',
            'played_at'   => now(),
        ]);

        // --- Store metadata from the Track object (key/value) ---
        foreach ($npTrack->meta ?? [] as $meta) {
            $this->storeTrackMetadata($track, $meta, $trackSource);
        }
    }

    private function closePreviousPlay(int $deviceId): void
    {
        Play::query()
            ->where('device_id', $deviceId)
            ->whereNull('ended_at')
            ->latest('played_at')
            ->first()
            ?->update(['ended_at' => now()]);
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
