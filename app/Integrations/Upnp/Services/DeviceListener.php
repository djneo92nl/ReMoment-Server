<?php

namespace App\Integrations\Upnp\Services;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\PlaybackModes;
use App\Domain\Device\RepeatMode;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Domain\Media\TrackData;
use App\Domain\Radio\IcyReader;
use App\Domain\Radio\Relay\RadioRelay;
use App\Domain\Radio\StreamTitles;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\PlaybackModesUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;
use App\Integrations\Common\DeviceQueue;
use App\Integrations\Common\ListenerBackoff;
use App\Integrations\Contracts\DeviceListenerInterface;
use App\Integrations\Upnp\DidlLite;
use App\Integrations\Upnp\MusicPlayerDriver;
use App\Integrations\Upnp\Renderer;
use App\Integrations\Upnp\UpnpCapabilities;
use App\Models\Device;
use App\Models\Media\Track;
use App\Models\RadioStation;
use Illuminate\Support\Facades\Log;

/**
 * Polls one renderer once a second (unicast, like the Sonos listener: no multicast, no inbound callback port,
 * a few small requests, fine on a Pi). Besides reporting what plays it runs the server's queue: when a track
 * ends by itself it starts the next, and near the end it hands a gapless renderer the next track.
 */
class DeviceListener implements DeviceListenerInterface
{
    private const POLL_SECONDS = 1;

    protected const VOLUME_EVERY_SECONDS = 3;

    /** A stop this close to the end of the track is the track ending. */
    private const END_MARGIN_SECONDS = 3;

    /** The next track is handed to a gapless renderer this long before the end. */
    private const PRELOAD_SECONDS = 10;

    private ?string $lastNowPlayingKey = null;

    private ?string $lastUri = null;

    private ?int $lastPosition = null;

    private ?int $lastDuration = null;

    private bool $wasPlaying = false;

    protected ?int $lastVolume = null;

    protected ?bool $lastMuted = null;

    private ?PlaybackModes $lastModes = null;

    protected int $nextVolumeCheck = 0;

    /** True while the renderer holds nothing playing (a subclass reports what else the device does then). */
    protected bool $transportIdle = true;

    public function __construct(protected readonly Device $device, private ?Renderer $renderer = null) {}

    public static function forDevice(Device $device): ?static
    {
        return new static($device);
    }

    public function listen(string $deviceId)
    {
        $backoff = new ListenerBackoff;
        $lastError = null;

        DeviceCache::updateState($deviceId, State::Unreachable);

        while (true) {
            DeviceCache::markListenerAlive($deviceId);

            try {
                $this->poll($deviceId);
                $backoff->next(failed: false);
                $lastError = null;
                sleep(self::POLL_SECONDS);
            } catch (\Throwable $e) {
                // Logged once per distinct message, so a renderer that is switched off does not flood the log.
                if ($e->getMessage() !== $lastError) {
                    Log::warning("UPnP listener {$deviceId} ({$this->device->ip_address}): ".get_class($e).': '.$e->getMessage());
                    $lastError = $e->getMessage();
                }
                DeviceCache::forgetListener($deviceId);
                DeviceCache::updateState($deviceId, State::Unreachable);
                $this->renderer = null;

                sleep($backoff->next(failed: true));
            }
        }
    }

    /** One look at the renderer: state, position, track, volume, and the queue's next step. */
    public function poll(string $deviceId): void
    {
        $renderer = $this->renderer ??= Renderer::forDevice($this->device);
        $id = (int) $deviceId;

        $state = $renderer->transportState();
        $position = $renderer->positionInfo();

        $this->reportVolume($renderer, $deviceId);
        $this->reportModes($deviceId);

        if (!in_array($state, ['PLAYING', 'TRANSITIONING', 'PAUSED_PLAYBACK', 'PAUSED_RECORDING'], true)) {
            $this->stopped($deviceId);

            return;
        }

        $this->transportIdle = false;
        $this->followQueue($id, $position['uri']);
        $this->followSpotifyItem($id, $position['uri']);

        $nowPlaying = $this->nowPlaying($id, $position);
        $key = md5(json_encode([$nowPlaying->track?->name, $nowPlaying->track?->artist?->name, $nowPlaying->album?->name, $position['uri']]));

        if ($key !== $this->lastNowPlayingKey) {
            event(new NowPlayingUpdated(deviceId: $deviceId, nowPlaying: $nowPlaying, sourceType: 'media', timestamp: null));
            $this->lastNowPlayingKey = $key;
        }

        if ($position['position'] !== null && $position['position'] !== $this->lastPosition) {
            event(new ProgressUpdated(deviceId: $deviceId, progress: $position['position']));
        }

        $this->lastUri = $position['uri'];
        $this->lastPosition = $position['position'];
        $this->lastDuration = $position['duration'];
        $this->wasPlaying = !in_array($state, ['PAUSED_PLAYBACK', 'PAUSED_RECORDING'], true);

        if (!$this->wasPlaying) {
            DeviceCache::updateState($id, State::Paused);

            return;
        }

        DeviceCache::updateState($id, State::Playing);
        $this->preloadWhenNearEnd($id);
    }

    /** The state while the renderer holds nothing playing; a subclass that sees the device doing something else says so. */
    protected function idleState(): State
    {
        return State::Standby;
    }

    /** The renderer holds nothing playing: a track that ended starts the next, anything else ends the now-playing. */
    private function stopped(string $deviceId): void
    {
        $id = (int) $deviceId;
        $this->transportIdle = true;

        // A Spotify track item ended and the renderer did not move on to the queued one by itself: start it.
        if ($this->driver()->startPendingSpotifyItem()) {
            return;
        }

        if ($this->lastNowPlayingKey !== null) {
            $this->lastNowPlayingKey = null;

            // The server just started another track (next, skip): the renderer is between two, not stopped.
            $started = MusicPlayerDriver::playing($id);
            if ($started !== null && $this->lastUri !== null && $started['uri'] !== $this->lastUri) {
                $this->lastUri = null;

                return;
            }

            $stoppedByUs = MusicPlayerDriver::stoppedByUs($id);

            if (!$stoppedByUs && MusicPlayerDriver::playing($id) !== null && $this->trackEnded() && $this->driver()->trackEnded()) {
                $this->lastPosition = $this->lastDuration = null;
                $this->wasPlaying = false;

                return;
            }

            event(new NowPlayingEnded(deviceId: $deviceId));
            MusicPlayerDriver::forgetPlaying($id);
            MusicPlayerDriver::forgetRadio($id);
        }

        $this->lastPosition = $this->lastDuration = null;
        $this->wasPlaying = false;
        DeviceCache::updateState($id, $this->idleState());
    }

    /** Did the track just before the stop play to its end? Without a known duration: it was playing, and we did not stop it. */
    private function trackEnded(): bool
    {
        if (!$this->lastDuration) {
            return $this->wasPlaying;
        }

        return $this->lastPosition !== null && $this->lastPosition >= $this->lastDuration - self::END_MARGIN_SECONDS;
    }

    /** The renderer moved on to the queued Spotify track item (gapless): it is the current one now. */
    private function followSpotifyItem(int $id, string $uri): void
    {
        $item = MusicPlayerDriver::spotifyItem($id);

        if ($item !== null && ($item['next']['uri'] ?? null) === $uri) {
            MusicPlayerDriver::promoteSpotifyItem($id);
        }
    }

    /** Keeps the queue in step with what the renderer plays: a gapless hand-over moves it on, a foreign track drops it. */
    private function followQueue(int $id, string $uri): void
    {
        $playing = MusicPlayerDriver::playing($id);

        if ($playing === null || $playing['uri'] === $uri) {
            return;
        }

        $next = MusicPlayerDriver::preloaded($id);

        if ($next !== null && $next['uri'] === $uri) {
            $this->driver()->preloadedStarted();

            return;
        }

        // Started from the renderer's own app: show it, leave the queue alone.
        MusicPlayerDriver::forgetPlaying($id);
    }

    private function preloadWhenNearEnd(int $id): void
    {
        if (
            $this->lastDuration === null || $this->lastPosition === null
            || $this->lastDuration - $this->lastPosition > self::PRELOAD_SECONDS
            || MusicPlayerDriver::playing($id) === null
            || MusicPlayerDriver::preloaded($id) !== null
            || !UpnpCapabilities::has($this->device, UpnpCapabilities::GAPLESS)
        ) {
            return;
        }

        try {
            $this->driver()->preloadNext();
        } catch (\Throwable $e) {
            // Not worth losing the listener over: the next track then starts when this one ends.
            Log::info("UPnP renderer {$id} refused the next track: ".$e->getMessage());
        }
    }

    protected function reportVolume(Renderer $renderer, string $deviceId): void
    {
        if (time() < $this->nextVolumeCheck || !UpnpCapabilities::has($this->device, 'volume_control')) {
            return;
        }

        $this->nextVolumeCheck = time() + self::VOLUME_EVERY_SECONDS;

        try {
            $volume = $renderer->volume();
            $muted = $renderer->muted();
        } catch (\Throwable) {
            return;
        }

        if ($volume !== $this->lastVolume || $muted !== $this->lastMuted) {
            event(new VolumeUpdated(deviceId: $deviceId, volume: $volume, muted: $muted));
            $this->lastVolume = $volume;
            $this->lastMuted = $muted;
        }
    }

    private function reportModes(string $deviceId): void
    {
        $queue = DeviceQueue::for((int) $deviceId);
        $modes = new PlaybackModes($queue->shuffle, RepeatMode::from($queue->repeat), null);

        if ($this->lastModes === null || !$modes->equals($this->lastModes)) {
            event(new PlaybackModesUpdated(deviceId: $deviceId, modes: $modes));
            $this->lastModes = $modes;
        }
    }

    /** What the renderer reports about the track; a renderer that drops our metadata gets it from the queue's track. */
    private function nowPlaying(int $id, array $position): NowPlaying
    {
        if ($radio = $this->radioNowPlaying($id, $position)) {
            return $radio;
        }

        $meta = DidlLite::parse($position['metadata']);
        $ours = MusicPlayerDriver::playing($id);

        if ($meta['title'] === '' && $ours !== null && $ours['uri'] === $position['uri'] && ($track = Track::with(['artist', 'album'])->find($ours['track_id']))) {
            $meta = ['title' => $track->name, 'artist' => $track->artist?->name ?? '', 'album' => $track->album?->name ?? '', 'art' => ''];
            $position['duration'] ??= $track->duration;
        }

        $images = $meta['art'] !== '' ? [$meta['art']] : [];
        $artist = $meta['artist'] !== '' ? new ArtistData(name: $meta['artist']) : null;
        $album = $meta['album'] !== '' ? new AlbumData(name: $meta['album'], images: $images, artist: $artist) : null;

        return new NowPlaying(
            track: new TrackData(
                name: $meta['title'] !== '' ? $meta['title'] : (basename((string) parse_url($position['uri'], PHP_URL_PATH)) ?: 'Unknown'),
                artist: $artist,
                duration: $position['duration'] ?: null,
                images: $images,
            ),
            album: $album,
            position: $position['position'] ?? 0,
            type: 'music',
            platform: 'media',
        );
    }

    /**
     * The station the server started on this renderer, while that stream still plays: the station is the radio, the
     * stream's ICY title ("Artist - Song", kept fresh by {@see StreamTitles}) the song. Null for anything else.
     *
     * @param  array{uri: string}  $position
     */
    private function radioNowPlaying(int $id, array $position): ?NowPlaying
    {
        $ours = MusicPlayerDriver::radio($id);

        if ($ours === null || $ours['uri'] !== $position['uri'] || !($station = RadioStation::with('meta')->find($ours['station_id']))) {
            return null;
        }

        StreamTitles::touch($station);

        // A station played through the relay keeps being asked for while it plays.
        if ($ours['uri'] === RadioRelay::url($station)) {
            RadioRelay::want($station);
        }

        $images = $station->image_url ? [$station->image_url] : [];
        $title = StreamTitles::get($station->id);
        [$artistName, $song] = $title !== null ? IcyReader::split($title) : [null, $station->name];

        return new NowPlaying(
            track: new TrackData(
                name: $song,
                artist: $artistName !== null ? new ArtistData(name: $artistName) : null,
                images: $images,
            ),
            position: 0,
            type: 'music',
            platform: 'radio',
            radio: new Radio(name: $station->name, genre: $station->genre, images: $images, id: $station->streamUrl()),
        );
    }

    private function driver(): MusicPlayerDriver
    {
        return $this->device->driver;
    }
}
