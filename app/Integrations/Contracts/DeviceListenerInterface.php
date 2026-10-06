<?php

namespace App\Integrations\Contracts;

use App\Models\Device;

/**
 * A long-running watcher that turns what a device reports (push stream, WebSocket or polling)
 * into the events in AppServiceProvider. A new integration ships one and registers it in
 * config/devices.php under 'listeners', keyed by its driver class.
 */
interface DeviceListenerInterface
{
    /** The listener for $device, or null when there is nothing to listen to right now (e.g. Spotify not connected). */
    public static function forDevice(Device $device): ?static;

    /** Never returns: it reconnects on its own and reports the device as unreachable meanwhile. */
    public function listen(string $deviceId);
}
