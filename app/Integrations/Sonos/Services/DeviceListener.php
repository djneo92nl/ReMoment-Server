<?php

namespace App\Integrations\Sonos\Services;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\PlaybackModes;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;
use App\Events\Device\BatteryUpdated;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\PlaybackModesUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;
use App\Integrations\Contracts\DeviceListenerInterface;
use App\Integrations\Sonos\PlayMode;
use App\Integrations\Sonos\SonosBattery;
use App\Integrations\Sonos\SonosInputs;
use App\Models\Device;
use duncan3dc\Sonos\Controller;
use duncan3dc\Sonos\Devices\Collection;
use duncan3dc\Sonos\Interfaces\NetworkInterface;
use duncan3dc\Sonos\Interfaces\PlayState;
use duncan3dc\Sonos\Network;
use duncan3dc\Sonos\State as SonosState;
use Illuminate\Support\Facades\Log;

class DeviceListener implements DeviceListenerInterface
{
    protected NetworkInterface $network;

    protected ?Controller $controller = null;

    protected int $pollIntervalSeconds = 1;

    /** A battery changes slowly, and a speaker without one has nothing to ask twice. */
    protected int $batteryIntervalSeconds = 60;

    /**
     * Talks to the one speaker at $ip (unicast): no multicast discovery, so it also works
     * where multicast does not reach, e.g. inside a Docker bridge network.
     */
    public function __construct(protected string $ip, ?NetworkInterface $network = null)
    {
        $this->network = $network ?? new Network((new Collection)->addIp($ip));
    }

    public static function forDevice(Device $device): ?static
    {
        return new static($device->ip_address);
    }

    public function listen(string $deviceId)
    {
        $cacheKey = "listener_running_{$deviceId}";
        $retryDelaySeconds = 1;
        $maxRetryDelaySeconds = 30;

        $lastNowPlayingKey = null;
        $lastPositionSeconds = null;
        $lastVolume = null;
        $lastMuted = null;
        $lastModes = null;
        $lastError = null;
        $nextBatteryCheck = 0;

        DeviceCache::updateState($deviceId, State::Unreachable);

        while (true) {
            cache()->put($cacheKey, true, now()->addSeconds(10));

            try {
                $controller = $this->getController();

                $state = $controller->getState();
                $details = $controller->getStateDetails();
                $volume = $controller->getVolume();
                $muted = $controller->isMuted();

                if ($lastVolume === null || $volume !== $lastVolume || $muted !== $lastMuted) {
                    event(new VolumeUpdated(deviceId: $deviceId, volume: $volume, muted: $muted));
                    $lastVolume = $volume;
                    $lastMuted = $muted;
                }

                if (time() >= $nextBatteryCheck) {
                    $nextBatteryCheck = time() + $this->batteryIntervalSeconds;
                    if ($battery = SonosBattery::fetch($this->ip)) {
                        event(new BatteryUpdated(deviceId: $deviceId, battery: $battery));
                    }
                }

                $modes = $this->readModes($controller);
                if ($lastModes === null || !$modes->equals($lastModes)) {
                    event(new PlaybackModesUpdated(deviceId: $deviceId, modes: $modes));
                    $lastModes = $modes;
                }

                if ($state === PlayState::Stopped) {
                    if ($lastNowPlayingKey !== null) {
                        event(new NowPlayingEnded(deviceId: $deviceId));
                        $lastNowPlayingKey = null;
                        $lastPositionSeconds = null;
                    }

                    // Reachable but idle: leave the initial "unreachable" state (no-op when unchanged).
                    DeviceCache::updateState($deviceId, State::Standby);
                } else {
                    $nowPlaying = $this->buildNowPlaying($details);
                    if ($nowPlaying !== null) {
                        $nowPlayingKey = $this->nowPlayingKey($nowPlaying);
                        if ($nowPlayingKey !== $lastNowPlayingKey) {
                            event(new NowPlayingUpdated(
                                deviceId: $deviceId,
                                nowPlaying: $nowPlaying,
                                sourceType: $nowPlaying->platform === 'radio' ? 'radio' : 'media',
                                timestamp: null
                            ));
                            $lastNowPlayingKey = $nowPlayingKey;
                        }
                    } else {
                        // Device is playing an unrecognised source (e.g. line-in with no metadata).
                        // Still mark as Playing so the UI doesn't fall back to Unreachable.
                        DeviceCache::updateState($deviceId, State::Playing);
                    }

                    $positionSeconds = $this->toSeconds($details->position ?? '');
                    if ($positionSeconds !== null && $positionSeconds !== $lastPositionSeconds) {
                        event(new ProgressUpdated(deviceId: $deviceId, progress: $positionSeconds));
                        $lastPositionSeconds = $positionSeconds;
                    }

                    if ($state === PlayState::Paused) {
                        DeviceCache::updateState($deviceId, State::Paused);
                    }
                }

                $retryDelaySeconds = 1;
                $lastError = null;
                sleep($this->pollIntervalSeconds);
            } catch (\Throwable $e) {
                // Logged once per distinct message, so an unreachable speaker does not flood the log.
                if ($e->getMessage() !== $lastError) {
                    Log::warning("Sonos listener {$deviceId} ({$this->ip}): ".get_class($e).': '.$e->getMessage());
                    $lastError = $e->getMessage();
                }
                cache()->forget($cacheKey);
                DeviceCache::updateState($deviceId, State::Unreachable);
                $this->controller = null;

                $retryDelaySeconds = min($retryDelaySeconds * 2, $maxRetryDelaySeconds);
                sleep($retryDelaySeconds);
            }
        }

    }

    protected function getController(): Controller
    {
        if ($this->controller === null) {
            $this->controller = $this->network->getControllerByIp($this->ip);
        }

        return $this->controller;
    }

    /** Shuffle and repeat from the transport's PlayMode; Sonos has no "liked". */
    protected function readModes(Controller $controller): PlaybackModes
    {
        try {
            $settings = $controller->soap('AVTransport', 'GetTransportSettings')->getArray();
        } catch (\Throwable) {
            // Not worth marking the speaker unreachable over.
            return new PlaybackModes;
        }

        [$shuffle, $repeat] = PlayMode::parse((string) ($settings['PlayMode'] ?? ''));

        return new PlaybackModes(shuffle: $shuffle, repeat: $repeat);
    }

    protected function buildNowPlaying(SonosState $details): ?NowPlaying
    {
        $title = trim($details->getTitle());
        $artistName = trim($details->getArtist());
        $albumName = trim($details->getAlbum());
        $stream = $details->getStream();
        $streamName = $stream ? trim($stream->getTitle()) : '';
        $albumArt = trim($details->getAlbumArt());
        $images = $albumArt !== '' ? [$albumArt] : [];

        if ($title === '' && $streamName === '') {
            return $this->buildInputNowPlaying($details);
        }

        $durationSeconds = $details->getDuration()->asInt() ?: null;
        $positionSeconds = $details->getPosition()->asInt();

        if ($streamName !== '') {
            $radio = new Radio(name: $streamName, images: $images);
            $artist = $artistName !== '' ? new ArtistData(name: $artistName) : null;
            $track = new TrackData(
                name: $title !== '' ? $title : $streamName,
                artist: $artist,
                duration: $durationSeconds,
                images: $images
            );
            $nowPlaying = new NowPlaying(
                track: $track,
                position: $positionSeconds,
                type: 'music',
                platform: 'radio',
                radio: $radio,
            );
        } else {
            $artist = $artistName !== '' ? new ArtistData(name: $artistName) : null;
            $album = $albumName !== '' ? new AlbumData(name: $albumName, images: $images, artist: $artist) : null;
            $nowPlaying = new NowPlaying(
                track: new TrackData(
                    name: $title,
                    artist: $artist,
                    duration: $durationSeconds,
                    images: $images
                ),
                album: $album,
                position: $positionSeconds,
                type: 'music',
                platform: 'media'
            );
        }

        return $nowPlaying;
    }

    /** A soundbar on its TV input or a speaker on line-in plays no track: report the input as the source. */
    protected function buildInputNowPlaying(SonosState $details): ?NowPlaying
    {
        $input = SonosInputs::idForUri($details->getUri());
        if ($input === null) {
            return null;
        }

        $source = SonosInputs::source($input, true);

        return new NowPlaying(
            state: 'playing',
            type: 'music',
            platform: 'media',
            source: new Source(name: $source->friendlyName, category: $source->category, sourceType: $source->sourceType),
        );
    }

    protected function nowPlayingKey(NowPlaying $nowPlaying): string
    {
        return md5(json_encode([
            'track' => $nowPlaying->track?->name,
            'artist' => $nowPlaying->track?->artist?->name,
            'album' => $nowPlaying->album?->name,
            'radio' => $nowPlaying->radio?->name,
            'source' => $nowPlaying->source?->name,
            'type' => $nowPlaying->type,
            'platform' => $nowPlaying->platform,
        ]));
    }

    protected function toSeconds(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = array_map('intval', explode(':', $value));
        if (count($parts) === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }

        if (count($parts) === 2) {
            return ($parts[0] * 60) + $parts[1];
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
