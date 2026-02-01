<?php

namespace App\Listeners\Device;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;

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
            DeviceCache::updateState($deviceId, State::Playing);

            (new DeviceCache)->updateNowPlaying($deviceId, $event->nowPlaying);

            return;
        }
        dump('hi');

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
    }
}
