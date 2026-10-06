<?php

namespace App\Listeners\Device;

use App\Domain\Device\Cache\Battery;
use App\Domain\Device\Cache\Modes;
use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Events\Device\BatteryUpdated;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\PlaybackModesUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;

class UpdateDeviceCache
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
        $deviceId = (int) ($event->deviceId ?? 0);

        if ($deviceId <= 0) {
            return;
        }

        if ($event instanceof NowPlayingUpdated) {
            // Now-playing first: a state transition republishes it to MQTT /data.
            (new DeviceCache)->updateNowPlaying($deviceId, $event->nowPlaying);

            DeviceCache::updateState($deviceId, State::Playing);

            return;
        }

        if ($event instanceof ProgressUpdated) {
            DeviceCache::updateState($deviceId, State::Playing);

            $nowPlaying = DeviceCache::getNowPlaying($deviceId);

            if ($nowPlaying instanceof NowPlaying) {
                $nowPlaying->position = $event->progress;
                (new DeviceCache)->updateNowPlaying($deviceId, $nowPlaying);
            }

            return;
        }

        if ($event instanceof NowPlayingEnded) {
            DeviceCache::updateState($deviceId, State::Standby);

            DeviceCache::forgetNowPlaying($deviceId);

            return;
        }

        if ($event instanceof VolumeUpdated) {
            Volume::updateVolume($deviceId, $event->volume);

            if ($event->muted !== null) {
                Volume::updateMuted($deviceId, $event->muted);
            }

            return;
        }

        if ($event instanceof BatteryUpdated) {
            Battery::put($deviceId, $event->battery);

            return;
        }

        if ($event instanceof PlaybackModesUpdated) {
            Modes::put($deviceId, $event->modes);

            return;
        }
    }
}
