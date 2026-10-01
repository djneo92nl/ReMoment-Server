<?php

namespace App\Listeners\Device;

use App\Domain\Device\Cache\Modes;
use App\Domain\Device\SpotifyRouting;
use App\Events\Device\PlaybackModesUpdated;

/**
 * Runs before the other PlaybackModesUpdated listeners. A device's own
 * reports are remembered (Modes::own) so they can be restored after Spotify
 * routing ends; while Spotify is routed to the device they are held back,
 * because Spotify's modes are the ones shown there.
 */
class HoldOwnModesWhileSpotifyRouted
{
    public function handle(PlaybackModesUpdated $event): ?bool
    {
        $deviceId = (int) $event->deviceId;
        if ($event->routed || $deviceId <= 0) {
            return null;
        }

        Modes::putOwn($deviceId, $event->modes);

        // Returning false stops the event reaching the cache and MQTT listeners.
        return SpotifyRouting::routedDeviceId() === $deviceId ? false : null;
    }
}
