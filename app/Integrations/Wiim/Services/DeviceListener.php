<?php

namespace App\Integrations\Wiim\Services;

use App\Domain\Device\BatteryStatus;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\PlaybackModes;
use App\Domain\Device\RepeatMode;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;
use App\Events\Device\BatteryUpdated;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\PlaybackModesUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;
use App\Integrations\Upnp\Renderer;
use App\Integrations\Upnp\Services\DeviceListener as UpnpListener;
use App\Integrations\Wiim\MusicPlayerDriver;
use App\Integrations\Wiim\WiimClient;

/**
 * The UPnP listener (what the server plays on the WiiM, and its queue), plus the WiiM's own HTTP API: volume and mute
 * every few seconds, and, while nothing of ours plays over UPnP, what the WiiM plays by itself (AirPlay, Spotify
 * Connect, a preset, an input) from `getPlayerStatus` and `getMetaInfo`. A few small requests every 3 s, a minute
 * apart for the battery; an unreachable device backs off like every UPnP listener (the base class).
 */
class DeviceListener extends UpnpListener
{
    private const NATIVE_EVERY_SECONDS = 3;

    private const BATTERY_EVERY_SECONDS = 60;

    /** The status read by the last volume check, so the now-playing check does not ask again. */
    private ?array $status = null;

    private int $statusAt = 0;

    private ?string $nativeKey = null;

    private bool $nativeActive = false;

    private ?string $nativeState = null;

    private ?PlaybackModes $nativeModes = null;

    private int $nextBatteryCheck = 0;

    protected function reportVolume(Renderer $renderer, string $deviceId): void
    {
        if (now()->timestamp < $this->nextVolumeCheck) {
            return;
        }

        $this->nextVolumeCheck = now()->timestamp + self::NATIVE_EVERY_SECONDS;
        $client = $this->client();

        try {
            $this->status = $client->playerStatus();
            $this->statusAt = now()->timestamp;
        } catch (\Throwable) {
            $this->status = null;

            return;
        }

        if ($this->status['volume'] !== $this->lastVolume || $this->status['muted'] !== $this->lastMuted) {
            event(new VolumeUpdated(deviceId: $deviceId, volume: $this->status['volume'], muted: $this->status['muted']));
            $this->lastVolume = $this->status['volume'];
            $this->lastMuted = $this->status['muted'];
        }

        $this->reportBattery($deviceId, $client);
    }

    public function poll(string $deviceId): void
    {
        parent::poll($deviceId);

        if (!$this->transportIdle) {
            // The server's own playback is what is heard: the parent reports it.
            $this->nativeActive = false;
            $this->nativeKey = $this->nativeState = null;

            return;
        }

        if ($this->status === null || now()->timestamp - $this->statusAt > self::NATIVE_EVERY_SECONDS + 2) {
            return;
        }

        $this->reportNative($deviceId, $this->status);
    }

    /** A WiiM playing by itself is the device playing, whatever the UPnP side holds. */
    protected function idleState(): State
    {
        return match ($this->nativeState) {
            'playing' => State::Playing,
            'paused' => State::Paused,
            default => State::Standby,
        };
    }

    private function reportNative(string $deviceId, array $status): void
    {
        $id = (int) $deviceId;
        $mode = $status['mode'];
        $playing = in_array($status['status'], ['play', 'loading'], true);
        $paused = $status['status'] === 'pause';

        // A group member (type 1, mode 99) plays what its leader plays: the leader reports it.
        if ($status['slave'] || $mode === 99 || (!$playing && !$paused)) {
            $this->endNative($deviceId);

            return;
        }

        $state = $paused ? 'paused' : 'playing';
        $this->reportModes($deviceId, $status['loop']);

        try {
            $meta = $this->client()->metaInfo();
        } catch (\Throwable) {
            $meta = ['title' => '', 'artist' => '', 'album' => '', 'art' => null, 'sample_rate' => null, 'bit_depth' => null];
        }

        $key = md5(json_encode([$mode, $meta['title'], $meta['artist'], $meta['album'], $state]));

        if ($key !== $this->nativeKey) {
            event(new NowPlayingUpdated(deviceId: $deviceId, nowPlaying: $this->nowPlaying($status, $meta, $state), sourceType: 'media', timestamp: null));
            $this->nativeKey = $key;
        }

        event(new ProgressUpdated(deviceId: $deviceId, progress: $status['position']));

        $this->nativeActive = true;
        $this->nativeState = $state;
        DeviceCache::updateState($id, $paused ? State::Paused : State::Playing);
    }

    private function endNative(string $deviceId): void
    {
        if (!$this->nativeActive) {
            return;
        }

        $this->nativeActive = false;
        $this->nativeKey = $this->nativeState = null;
        event(new NowPlayingEnded(deviceId: $deviceId));
        DeviceCache::updateState((int) $deviceId, State::Standby);
    }

    private function reportModes(string $deviceId, int $loop): void
    {
        [$shuffle, $repeat] = WiimClient::LOOP_STATUS[$loop] ?? [false, 'off'];
        $modes = new PlaybackModes($shuffle, RepeatMode::from($repeat), null);

        if ($this->nativeModes === null || !$modes->equals($this->nativeModes)) {
            event(new PlaybackModesUpdated(deviceId: $deviceId, modes: $modes));
            $this->nativeModes = $modes;
        }
    }

    private function reportBattery(string $deviceId, WiimClient $client): void
    {
        if (now()->timestamp < $this->nextBatteryCheck) {
            return;
        }

        $this->nextBatteryCheck = now()->timestamp + self::BATTERY_EVERY_SECONDS;

        try {
            $battery = MusicPlayerDriver::batteryOf($client->statusEx());
        } catch (\Throwable) {
            return;
        }

        $battery instanceof BatteryStatus && event(new BatteryUpdated(deviceId: $deviceId, battery: $battery));
    }

    private function nowPlaying(array $status, array $meta, string $state): NowPlaying
    {
        [$sourceName, $category, $sourceType] = WiimClient::MODES[$status['mode']] ?? ['WiiM', 'music', 'MUSIC'];
        $images = $meta['art'] ? [$meta['art']] : [];
        $artist = $meta['artist'] !== '' ? new ArtistData(name: $meta['artist']) : null;
        $album = $meta['album'] !== '' ? new AlbumData(name: $meta['album'], images: $images, artist: $artist) : null;

        return new NowPlaying(
            track: new TrackData(name: $meta['title'] !== '' ? $meta['title'] : $sourceName, artist: $artist, duration: $status['duration'] ?: null, images: $images),
            album: $album,
            state: $state,
            position: $status['position'],
            type: 'music',
            platform: 'media',
            source: new Source(name: $sourceName, category: $category, sourceType: $sourceType, connector: 'wiim', playsLocally: true),
        );
    }

    private function client(): WiimClient
    {
        return WiimClient::forDevice($this->device);
    }
}
