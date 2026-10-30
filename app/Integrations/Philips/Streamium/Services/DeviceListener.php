<?php

namespace App\Integrations\Philips\Streamium\Services;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Integrations\Philips\Streamium\MusicPlayerDriver;
use App\Integrations\Upnp\Services\DeviceListener as UpnpListener;
use Illuminate\Support\Str;

/**
 * The UPnP listener, plus: while the unit plays nothing we gave it over UPnP, what its own local source plays
 * (internet radio) is read from the web remote and reported, the way the Philips TV listener reports the app in
 * front. Asked every few seconds only. A unit the server put to standby is not asked: it keeps counting its
 * elapsed time while asleep, so the web remote cannot tell asleep from playing.
 */
class DeviceListener extends UpnpListener
{
    private const REMOTE_EVERY_SECONDS = 3;

    private int $nextRemoteCheck = 0;

    /** What the unit's local source was last reported as: null (nothing), `playing` or `paused`. */
    private ?string $localState = null;

    private ?string $localKey = null;

    public function poll(string $deviceId): void
    {
        parent::poll($deviceId);

        if (!$this->transportIdle) {
            // Something we started over UPnP plays: the parent reports it, the local source is not what is heard.
            $this->localState = $this->localKey = null;
            MusicPlayerDriver::forgetLocal((int) $deviceId);

            return;
        }

        if (now()->timestamp < $this->nextRemoteCheck) {
            return;
        }

        $this->nextRemoteCheck = now()->timestamp + self::REMOTE_EVERY_SECONDS;
        $this->reportLocal($deviceId);
    }

    /** A local source playing is the unit playing, whatever the UPnP side holds: no flapping between the two each poll. */
    protected function idleState(): State
    {
        return match ($this->localState) {
            'playing' => State::Playing,
            'paused' => State::Paused,
            default => State::Standby,
        };
    }

    private function reportLocal(string $deviceId): void
    {
        $id = (int) $deviceId;

        if (MusicPlayerDriver::believedAsleep($id)) {
            $this->endLocal($deviceId);

            return;
        }

        /** @var MusicPlayerDriver $driver */
        $driver = $this->device->driver;

        try {
            $status = $driver->remote()->status();

            // Only change signals this time: not an answer, so what was reported stands.
            if ($status === null) {
                return;
            }

            $screen = $status['playing'] || $status['paused'] ? $driver->remote()->screen() : ['source' => null, 'item' => null];
        } catch (\Throwable) {
            return; // The UPnP side already tells reachable from not.
        }

        // Radio has a title to show; AUX is only a source. A disc is not reported: an empty drive answers like a playing one.
        $nowPlaying = match (true) {
            $screen['item'] !== null && $screen['source'] === 'radio' => fn (string $state) => $this->radioNowPlaying($screen['item'], $state),
            $screen['source'] === 'aux' => fn (string $state) => $this->auxNowPlaying($state),
            default => null,
        };

        if ($nowPlaying === null) {
            $this->endLocal($deviceId);

            return;
        }

        // A live radio stream has no pause: the player's pause button mutes it, so a muted stream is the paused one.
        $state = $status['paused'] || $status['muted'] ? 'paused' : 'playing';
        $key = md5(json_encode([$screen['source'], $screen['item']['title'] ?? null, $screen['item']['station'] ?? null, $state]));
        MusicPlayerDriver::markLocal($id);

        if ($key !== $this->localKey) {
            event(new NowPlayingUpdated(
                deviceId: $deviceId,
                nowPlaying: $nowPlaying($state),
                sourceType: 'media',
                timestamp: null,
            ));
        }

        $this->localKey = $key;
        $this->localState = $state;
        DeviceCache::updateState($id, $state === 'paused' ? State::Paused : State::Playing);
    }

    private function endLocal(string $deviceId): void
    {
        MusicPlayerDriver::forgetLocal((int) $deviceId);

        if ($this->localState === null) {
            return;
        }

        $this->localState = $this->localKey = null;
        event(new NowPlayingEnded(deviceId: $deviceId));
        DeviceCache::updateState((int) $deviceId, State::Standby);
    }

    /** @param array{title: string, station: string|null, art: string|null, volume: int, max_volume: int, elapsed: int} $item */
    private function radioNowPlaying(array $item, string $state): NowPlaying
    {
        $station = $item['station'] ?? $item['title'];
        $song = Str::startsWith($item['title'], $station.' - ') ? Str::after($item['title'], $station.' - ') : $item['title'];
        $images = $item['art'] ? [$item['art']] : [];

        return new NowPlaying(
            track: new TrackData(name: $song, images: $images),
            state: $state,
            type: 'music',
            platform: 'radio',
            radio: new Radio(name: $station, images: $images),
            source: new Source(name: 'Internet radio', category: 'radio', sourceType: 'RADIO', connector: 'streamium', playsLocally: true),
        );
    }

    /** The AUX input: no track, only the source (the logo is the generated line-in one). */
    private function auxNowPlaying(string $state): NowPlaying
    {
        return new NowPlaying(
            state: $state,
            type: 'music',
            platform: 'media',
            source: new Source(name: 'AUX', category: 'music', sourceType: 'LINE_IN', connector: 'streamium', playsLocally: true),
        );
    }
}
