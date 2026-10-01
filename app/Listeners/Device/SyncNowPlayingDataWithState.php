<?php

namespace App\Listeners\Device;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Events\Device\DeviceStateChanged;

/**
 * Keeps the retained MQTT `/data` in line with the device state: cleared on
 * standby/unreachable, and republished from the cache when a device comes
 * back (a listener that recovers from an error doesn't re-announce a track
 * it already reported).
 */
class SyncNowPlayingDataWithState
{
    public function __construct(
        protected PublishNowPlayingToMqtt $publisher
    ) {}

    public function handle(DeviceStateChanged $event): void
    {
        if (in_array($event->state, [State::Standby, State::Unreachable], true)) {
            $this->publisher->clear($event->deviceId);

            return;
        }

        $wasIdle = $event->previous === null || in_array($event->previous, [State::Standby, State::Unreachable], true);
        $nowPlaying = $wasIdle ? DeviceCache::getNowPlaying($event->deviceId) : null;

        if ($nowPlaying !== null) {
            $this->publisher->publish($event->deviceId, $nowPlaying);
        }
    }
}
