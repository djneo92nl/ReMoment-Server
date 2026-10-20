<?php

namespace App\Domain\Spotify;

use App\Domain\Library\SpotifyUri;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The librespot container's `--onevent` hook calls in here (POST /api/librespot/event). While a speaker plays
 * the Icecast stream ({@see self::target()}) the events become its now playing, unless Spotify's own listener already
 * reports it ({@see LibrespotStream::polledFor()}); the title always goes into Icecast's stream metadata for speakers
 * that show it. See docs/architecture/spotify-stream.md.
 */
class LibrespotEvents
{
    private const TARGET_KEY = 'librespot:target';

    private const TRACK_KEY = 'librespot:track';

    /** The speaker the stream was last started on: the one the events are reported for. */
    public static function target(): ?int
    {
        $id = Cache::get(self::TARGET_KEY);

        return $id === null ? null : (int) $id;
    }

    public static function setTarget(int $deviceId): void
    {
        Cache::put(self::TARGET_KEY, $deviceId, now()->addHours(12));
    }

    public static function clearTarget(): void
    {
        Cache::forget(self::TARGET_KEY);
    }

    /** @param array<string, mixed> $event the hook's fields: event, track_id, position_ms, duration_ms, name, artists, album, covers */
    public function handle(array $event): void
    {
        $name = (string) ($event['event'] ?? '');

        // Only `track_changed` carries the metadata; `playing` and `seeked` only the track id and position.
        if ($name === 'track_changed' && trim((string) ($event['name'] ?? '')) !== '') {
            Cache::put(self::TRACK_KEY, $this->track($event), now()->addHours(12));
            $this->setIcecastTitle($event);
        }

        $deviceId = self::target();

        // With Spotify connected and the receiver mapped to the speaker, Spotify's own listener reports the playback
        // for it (and the Spotify card folds into the speaker's): reporting it here too would double every event.
        if ($deviceId === null || LibrespotStream::polledFor($deviceId)) {
            return;
        }

        match ($name) {
            'track_changed', 'playing' => $this->report($deviceId, $event),
            'seeked', 'position_correction' => $this->progress($deviceId, $event),
            'session_disconnected', 'stopped' => $this->end($deviceId),
            default => null,
        };
    }

    /** @return array{id: string, name: string, artist: string, album: string, images: list<string>, duration: int} */
    private function track(array $event): array
    {
        $covers = array_values(array_filter(preg_split('/\R/', (string) ($event['covers'] ?? '')) ?: []));

        return [
            'id' => trim((string) ($event['track_id'] ?? '')),
            'name' => trim((string) $event['name']),
            // librespot separates several artists with newlines: the first is the main one.
            'artist' => trim(strtok((string) ($event['artists'] ?? ''), "\n") ?: ''),
            'album' => trim((string) ($event['album'] ?? '')),
            // librespot lists the covers smallest first; the largest is the last.
            'images' => $covers ? [end($covers)] : [],
            'duration' => (int) round(((int) ($event['duration_ms'] ?? 0)) / 1000),
        ];
    }

    private function report(int $deviceId, array $event): void
    {
        $track = Cache::get(self::TRACK_KEY);

        if (!$track) {
            return;
        }

        $artist = $track['artist'] !== '' ? new ArtistData(name: $track['artist']) : null;
        $spotifyId = $track['id'] !== '' ? SpotifyUri::track($track['id']) : null;

        event(new NowPlayingUpdated(
            deviceId: (string) $deviceId,
            nowPlaying: new NowPlaying(
                track: new TrackData(
                    id: $spotifyId,
                    name: $track['name'],
                    source: 'spotify',
                    artist: $artist,
                    duration: $track['duration'],
                    images: $track['images'],
                    meta: $spotifyId ? [['spotifyId' => $spotifyId]] : [],
                ),
                album: $track['album'] !== '' ? new AlbumData(name: $track['album'], images: $track['images'], artist: $artist) : null,
                position: $this->seconds($event),
                type: 'music',
                platform: 'media',
            ),
            sourceType: 'spotify',
        ));
    }

    private function progress(int $deviceId, array $event): void
    {
        event(new ProgressUpdated(deviceId: (string) $deviceId, progress: $this->seconds($event)));
    }

    private function end(int $deviceId): void
    {
        event(new NowPlayingEnded(deviceId: (string) $deviceId));
    }

    private function seconds(array $event): int
    {
        return (int) round(((int) ($event['position_ms'] ?? 0)) / 1000);
    }

    /** Icecast shows the title to players that read stream metadata; a failure here never matters to the stream. */
    private function setIcecastTitle(array $event): void
    {
        $artist = trim(strtok((string) ($event['artists'] ?? ''), "\n") ?: '');
        $song = $artist !== '' ? "$artist - {$event['name']}" : (string) $event['name'];

        try {
            Http::timeout(3)
                ->withBasicAuth(config('librespot.icecast.admin_user'), config('librespot.icecast.admin_password'))
                ->get(rtrim(config('librespot.icecast.internal_url'), '/').'/admin/metadata', [
                    'mount' => '/'.ltrim((string) config('librespot.icecast.mount'), '/'),
                    'mode' => 'updinfo',
                    'song' => $song,
                ]);
        } catch (\Throwable $e) {
            Log::debug('Could not set the Icecast title: '.$e->getMessage());
        }
    }
}
