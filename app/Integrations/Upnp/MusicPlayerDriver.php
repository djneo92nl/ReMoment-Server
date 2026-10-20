<?php

namespace App\Integrations\Upnp;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Device\Cache\Volume;
use App\Domain\Device\PlaybackModes;
use App\Domain\Device\QueueItem;
use App\Domain\Device\RepeatMode;
use App\Domain\Device\Settings\DeviceInfo;
use App\Domain\Radio\StreamTitles;
use App\Domain\Storage\StreamUrl;
use App\Events\Device\PlaybackModesUpdated;
use App\Integrations\Common\DeviceQueue;
use App\Integrations\Common\EditsDeviceQueue;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\DeviceInfoInterface;
use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\PlayStreamUrlInterface;
use App\Integrations\Contracts\QueueAddInterface;
use App\Integrations\Contracts\QueueClearInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\QueueJumpInterface;
use App\Integrations\Contracts\QueueMoveInterface;
use App\Integrations\Contracts\QueuePlayNextInterface;
use App\Integrations\Contracts\QueueRemoveInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\ReportsStreamUrlInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Integrations\ReMoment\PlayerDevice;
use App\Models\Device;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\RadioStation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Any UPnP/DLNA MediaRenderer without a driver of its own. The renderer plays the one URL it is given;
 * this driver keeps the queue (DeviceQueue: next, previous, shuffle, repeat, up next) and the listener
 * moves it on when a track ends. Seek and volume depend on what the renderer's SCPDs list
 * ({@see UpnpCapabilities}, applied by HardwareFeatures). See docs/architecture/upnp-renderer.md.
 */
class MusicPlayerDriver implements DeviceInfoInterface, LibraryPlaybackInterface, MediaControlsInterface, MusicPlayerDriverInterface, PlayStreamUrlInterface, QueueAddInterface, QueueClearInterface, QueueInterface, QueueJumpInterface, QueueMoveInterface, QueuePlayNextInterface, QueueRemoveInterface, RadioControlInterface, RepeatInterface, ReportsStreamUrlInterface, SeekInterface, ShuffleInterface, VolumeControlInterface
{
    use EditsDeviceQueue;

    /** How long we remember that we stopped the renderer ourselves, so the listener doesn't read it as a track ending. */
    private const STOPPED_BY_US_SECONDS = 20;

    private ?Renderer $renderer = null;

    public function __construct(public Device $device) {}

    protected function canQueue(Track $track): bool
    {
        return (bool) $track->getDlnaUrl();
    }

    protected function queueUri(Track $track): ?string
    {
        return $this->urlFor($track);
    }

    protected function queueActive(): bool
    {
        return self::playing($this->device->id) !== null;
    }

    /** The track after the current one may have changed: what the renderer holds ahead of time must follow. */
    protected function queueEdited(int $position): void
    {
        if ($position === 1) {
            $this->refreshPreloaded();
        }
    }

    /** Connecting is lazy: building the driver (capability checks, lists) never waits for the renderer. */
    public function renderer(): Renderer
    {
        return $this->renderer ??= Renderer::forDevice($this->device);
    }

    public function play(): void
    {
        $queue = DeviceQueue::for($this->device->id);

        // Nothing loaded (after a stop or a restart of the renderer): start the queue's current track.
        if ($queue->current() !== null && in_array($this->renderer()->transportState(), ['STOPPED', 'NO_MEDIA_PRESENT'], true)) {
            $this->playCurrent($queue);

            return;
        }

        $this->renderer()->play();
    }

    public function pause(): void
    {
        $this->renderer()->pause();
    }

    public function stop(): void
    {
        Cache::put(self::stoppedKey($this->device->id), true, self::STOPPED_BY_US_SECONDS);
        $this->renderer()->stop();
    }

    public function next(): void
    {
        $this->startTrack(fn (DeviceQueue $queue) => $queue->advance(manual: true));
    }

    public function previous(): void
    {
        $this->startTrack(fn (DeviceQueue $queue) => $queue->back());
    }

    public function skipToQueuePosition(int $position): void
    {
        $this->startTrack(fn (DeviceQueue $queue) => $queue->jump(max(1, $position)));
    }

    public function seek(int $seconds): void
    {
        $this->renderer()->seek(max(0, $seconds));
    }

    public function getUpNext(int $limit = 20): array
    {
        // A track started from the renderer's own app is not ours: there is no queue behind it.
        if (self::playing($this->device->id) === null) {
            return [];
        }

        $ids = DeviceQueue::for($this->device->id)->upNext($limit);
        $tracks = Track::with(['artist', 'album'])->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->filter(fn ($id) => $tracks->has($id))->map(function ($id) use ($tracks) {
            $track = $tracks[$id];

            return new QueueItem(
                name: $track->name,
                artist: $track->artist?->name,
                album: $track->album?->name,
                image: PlayerDevice::cover($track),
                duration: $track->duration,
                uri: $this->urlFor($track),
            );
        })->values()->all();
    }

    public function setShuffle(bool $shuffle): void
    {
        $queue = DeviceQueue::for($this->device->id);
        $queue->setShuffle($shuffle);
        $queue->save($this->device->id);
        $this->refreshPreloaded();
        $this->reportModes($queue);
    }

    public function setRepeat(RepeatMode $mode): void
    {
        $queue = DeviceQueue::for($this->device->id);
        $queue->repeat = $mode->value;
        $queue->save($this->device->id);
        $this->refreshPreloaded();
        $this->reportModes($queue);
    }

    public function setVolume(int $volume): int
    {
        $volume = max(0, min(100, $volume));
        $this->renderer()->setVolume($volume);
        Volume::updateVolume($this->device->id, $volume);

        return $volume;
    }

    public function getVolume(): int
    {
        return Volume::remember($this->device->id, fn () => $this->renderer()->volume());
    }

    public function incrementVolume(): void
    {
        $this->setVolume($this->getVolume() + 5);
    }

    public function decrementVolume(): void
    {
        $this->setVolume($this->getVolume() - 5);
    }

    public function mute(): void
    {
        $this->renderer()->setMuted(true);
        Volume::updateMuted($this->device->id, true);
    }

    public function unmute(): void
    {
        $this->renderer()->setMuted(false);
        Volume::updateMuted($this->device->id, false);
    }

    public function isMuted(): bool
    {
        return Volume::getMuted($this->device->id) ?? $this->renderer()->muted();
    }

    public function getDeviceInfo(): DeviceInfo
    {
        return new DeviceInfo(name: $this->device->device_name, productType: $this->device->device_product_type, renamable: false);
    }

    public function setDeviceName(string $name): void
    {
        throw new UnsupportedOperationException('A UPnP renderer cannot be renamed from here.');
    }

    public function radioPlatform(): string
    {
        return 'url';
    }

    public function canPlayRadioStation(RadioStation $station): bool
    {
        return $station->streamUrl() !== null;
    }

    /** The stream goes to the renderer as it is; our listener adds the station and, from the ICY title, the song. */
    public function playRadioStation(RadioStation $station): void
    {
        $url = $station->streamUrl() ?? throw new UnsupportedOperationException('This station has no stream URL.');

        $this->playStreamUrl($url, $station->name, ($cover = LibraryItemArtwork::proxy($station->image_url, 320)) ? (StreamUrl::storage($cover) ?? $cover) : null);

        Cache::put(self::radioKey($this->device->id), ['station_id' => $station->id, 'uri' => $url], now()->addDay());
        StreamTitles::touch($station);
    }

    /** @return array{station_id: int, uri: string}|null the station the server started on this renderer */
    public static function radio(int $deviceId): ?array
    {
        return Cache::get(self::radioKey($deviceId));
    }

    public static function forgetRadio(int $deviceId): void
    {
        Cache::forget(self::radioKey($deviceId));
    }

    private static function radioKey(int $deviceId): string
    {
        return "upnp:{$deviceId}:radio";
    }

    public function loadedStreamUrl(): ?string
    {
        return $this->renderer()->positionInfo()['uri'] ?: null;
    }

    /** A live stream has no end: nothing of ours is "playing" afterwards, so the listener never moves the queue on. */
    public function playStreamUrl(string $url, string $title, ?string $art = null): void
    {
        self::forgetPlaying($this->device->id);
        self::forgetRadio($this->device->id);

        Cache::put(self::stoppedKey($this->device->id), true, self::STOPPED_BY_US_SECONDS);
        try {
            $this->renderer()->stop();
        } catch (\Throwable) {
            // Nothing to stop (NO_MEDIA_PRESENT) is fine.
        }

        $didl = DidlLite::build(url: $url, title: $title, artist: null, album: null, art: $art, duration: null, mime: 'audio/mpeg');
        $this->renderer()->setUri($url, $didl);
        $this->renderer()->play();
        Cache::forget(self::stoppedKey($this->device->id));
    }

    public function playLibraryTrack(Track $track): void
    {
        $this->playLibraryTracks(collect([$track]));
    }

    public function playLibraryTracks(Collection $tracks): void
    {
        $playable = $tracks->filter(fn (Track $track) => (bool) $track->getDlnaUrl())->values();

        if ($playable->isEmpty()) {
            throw new \RuntimeException('No tracks with a playable URL.');
        }

        $queue = DeviceQueue::for($this->device->id);
        $queue->replace($playable->pluck('id')->all());
        $queue->save($this->device->id);

        $this->playCurrent($queue);
    }

    public function playLibraryPlaylist(Playlist $playlist): void
    {
        $tracks = $playlist->tracks()->get();

        if ($tracks->isEmpty()) {
            throw new \RuntimeException("Playlist {$playlist->id} has no tracks.");
        }

        $this->playLibraryTracks($tracks);
    }

    /**
     * Called by the listener when a track ended by itself: starts the next one.
     *
     * @return bool false at the end of the queue (nothing started)
     */
    public function trackEnded(): bool
    {
        $queue = DeviceQueue::for($this->device->id);

        if ($queue->advance() === null) {
            $queue->save($this->device->id);
            self::forgetPlaying($this->device->id);

            return false;
        }

        $queue->save($this->device->id);
        $this->playCurrent($queue);

        return true;
    }

    /** Hands the renderer the track after the current one, so it moves on without a gap. Called by the listener near the end of a track. */
    public function preloadNext(): void
    {
        $queue = DeviceQueue::for($this->device->id);
        $id = $queue->peekNext();
        $track = $id ? Track::with(['artist', 'album'])->find($id) : null;
        $url = $track ? $this->urlFor($track) : null;

        if (!$track || !$url) {
            return;
        }

        $this->renderer()->setNextUri($url, $this->didl($track, $url));
        Cache::put(self::nextKey($this->device->id), ['track_id' => $track->id, 'uri' => $url], now()->addHour());
    }

    /** The renderer moved on to the preloaded track by itself: the queue follows. */
    public function preloadedStarted(): void
    {
        $queue = DeviceQueue::for($this->device->id);
        $queue->advance();
        $queue->save($this->device->id);

        $next = Cache::pull(self::nextKey($this->device->id));
        $next && Cache::put(self::playingKey($this->device->id), $next, now()->addDay());
    }

    /** @return array{track_id: int, uri: string}|null what the server last started on this renderer; null once something else plays */
    public static function playing(int $deviceId): ?array
    {
        return Cache::get(self::playingKey($deviceId));
    }

    /** @return array{track_id: int, uri: string}|null the track handed over ahead of time */
    public static function preloaded(int $deviceId): ?array
    {
        return Cache::get(self::nextKey($deviceId));
    }

    public static function forgetPlaying(int $deviceId): void
    {
        Cache::forget(self::playingKey($deviceId));
        Cache::forget(self::nextKey($deviceId));
    }

    /** True once if our own stop() was the reason the renderer stopped. */
    public static function stoppedByUs(int $deviceId): bool
    {
        return (bool) Cache::pull(self::stoppedKey($deviceId));
    }

    private function startTrack(\Closure $move): void
    {
        $queue = DeviceQueue::for($this->device->id);

        if ($move($queue) === null) {
            $this->stop();

            return;
        }

        $queue->save($this->device->id);
        $this->playCurrent($queue);
    }

    private function playCurrent(DeviceQueue $queue): void
    {
        $track = Track::with(['artist', 'album'])->find($queue->current());
        $url = $track ? $this->urlFor($track) : null;

        if (!$track || !$url) {
            throw new \RuntimeException('The queue has no playable track.');
        }

        Cache::forget(self::nextKey($this->device->id));

        // After a track has played, a Philips TV answers the next SetAVTransportURI with error 705 until it is
        // stopped. A stop is harmless to the others; it is ours, so the listener does not read it as a track ending.
        Cache::put(self::stoppedKey($this->device->id), true, self::STOPPED_BY_US_SECONDS);
        try {
            $this->renderer()->stop();
        } catch (\Throwable) {
            // Nothing to stop (NO_MEDIA_PRESENT) is not an error worth stopping the track for.
        }

        $this->renderer()->setUri($url, $this->didl($track, $url));
        $this->renderer()->play();
        Cache::forget(self::stoppedKey($this->device->id));
        Cache::put(self::playingKey($this->device->id), ['track_id' => $track->id, 'uri' => $url], now()->addDay());
    }

    /** The URL the renderer gets: our own stream with the file's extension, any other (a DLNA server's) as it is. */
    private function urlFor(Track $track): ?string
    {
        $url = $track->getDlnaUrl();

        if ($url === null || ($id = StreamUrl::trackId($url)) === null) {
            return $url;
        }

        return StreamUrl::withExtension($id, $track->trackFiles()->with('file')->get()->pluck('file.extension')->filter()->first());
    }

    private function didl(Track $track, string $url): string
    {
        $cover = LibraryItemArtwork::proxy(PlayerDevice::cover($track), 320);

        return DidlLite::build(
            url: $url,
            title: $track->name,
            artist: $track->artist?->name,
            album: $track->album?->name,
            art: $cover ? (StreamUrl::storage($cover) ?? $cover) : null,
            duration: $track->duration,
            mime: DidlLite::mimeFor($url, $track),
        );
    }

    /** The queue's next track changed (shuffle, repeat): what the renderer holds ahead of time must follow. */
    private function refreshPreloaded(): void
    {
        if (self::preloaded($this->device->id) === null) {
            return;
        }

        Cache::forget(self::nextKey($this->device->id));
        $this->preloadNext();
    }

    private function reportModes(DeviceQueue $queue): void
    {
        event(new PlaybackModesUpdated(
            deviceId: (string) $this->device->id,
            modes: new PlaybackModes($queue->shuffle, RepeatMode::from($queue->repeat), null),
        ));
    }

    private static function playingKey(int $deviceId): string
    {
        return "upnp:{$deviceId}:playing";
    }

    private static function nextKey(int $deviceId): string
    {
        return "upnp:{$deviceId}:next";
    }

    private static function stoppedKey(int $deviceId): string
    {
        return "upnp:{$deviceId}:stopped";
    }
}
