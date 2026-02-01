<?php

namespace App\Integrations\Sonos\Services;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\Album;
use App\Domain\Media\Artist;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Domain\Media\Track;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;
use duncan3dc\Sonos\Controller;
use duncan3dc\Sonos\Device;
use duncan3dc\Sonos\Network;
use duncan3dc\Sonos\State as SonosState;

class DeviceListener
{
    protected Network $network;

    protected ?Controller $controller = null;

    protected int $pollIntervalSeconds = 1;

    public function __construct(protected Device $device, ?Network $network = null)
    {
        $this->network = $network ?? new Network;
    }

    public function listen(string $deviceId)
    {
        $cacheKey = "listener_running_{$deviceId}";
        $retryDelaySeconds = 1;
        $maxRetryDelaySeconds = 30;

        $lastNowPlayingKey = null;
        $lastPositionSeconds = null;
        $lastVolume = null;

        while (true) {
            cache()->put($cacheKey, true, now()->addSeconds(10));

            try {
                $controller = $this->getController();

                $state = $controller->getState();
                $details = $controller->getStateDetails();
                $volume = $controller->getVolume();

                if ($lastVolume === null || $volume !== $lastVolume) {
                    event(new VolumeUpdated(deviceId: $deviceId, volume: $volume));
                    $lastVolume = $volume;
                }

                if ($state === Controller::STATE_STOPPED) {
                    if ($lastNowPlayingKey !== null) {
                        event(new NowPlayingEnded(deviceId: $deviceId));
                        $lastNowPlayingKey = null;
                        $lastPositionSeconds = null;
                    }
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
                    }

                    $positionSeconds = $this->toSeconds($details->position ?? '');
                    if ($positionSeconds !== null && $positionSeconds !== $lastPositionSeconds) {
                        event(new ProgressUpdated(deviceId: $deviceId, progress: $positionSeconds));
                        $lastPositionSeconds = $positionSeconds;
                    }
                }

                $retryDelaySeconds = 1;
                sleep($this->pollIntervalSeconds);
            } catch (\Throwable $e) {
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
            $this->controller = $this->network->getControllerByIp($this->device->ip);
        }

        return $this->controller;
    }

    protected function buildNowPlaying(SonosState $details): ?NowPlaying
    {
        $title = trim((string) ($details->title ?? ''));
        $artistName = trim((string) ($details->artist ?? ''));
        $albumName = trim((string) ($details->album ?? ''));
        $streamName = trim((string) ($details->stream ?? ''));
        $albumArt = trim((string) ($details->albumArt ?? ''));
        $images = $albumArt !== '' ? [$albumArt] : [];

        if ($title === '' && $streamName === '') {
            return null;
        }

        $durationSeconds = $this->toSeconds($details->duration ?? '');
        $positionSeconds = $this->toSeconds($details->position ?? '');

        if ($streamName !== '') {
            $radio = new Radio(name: $streamName, images: $images);
            $artist = $artistName !== '' ? new Artist(name: $artistName) : null;
            $track = new Track(
                name: $title !== '' ? $title : $streamName,
                artist: $artist,
                duration: $durationSeconds,
                images: $images
            );
            $nowPlaying = new NowPlaying(
                track: $track,
                artist: $artist,
                position: $positionSeconds,
                type: 'music',
                platform: 'radio',
                radio: $radio,
            );
        } else {
            $artist = $artistName !== '' ? new Artist(name: $artistName) : null;
            $album = $albumName !== '' ? new Album(name: $albumName, images: $images, artist: $artist) : null;
            $nowPlaying = new NowPlaying(
                track: new Track(
                    name: $title,
                    artist: $artist,
                    duration: $durationSeconds,
                    images: $images
                ),
                artist: $artist,
                album: $album,
                position: $positionSeconds,
                type: 'music',
                platform: 'media'
            );
        }

        return $nowPlaying;
    }

    protected function nowPlayingKey(NowPlaying $nowPlaying): string
    {
        return md5(json_encode([
            'track' => $nowPlaying->track?->name,
            'artist' => $nowPlaying->artist?->name,
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
