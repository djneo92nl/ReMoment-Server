<?php

namespace App\Domain\Device;

use App\Integrations\Contracts\DeviceListenerInterface;
use App\Models\Device;

/** Finds the listener for a device through the driver map in config/devices.php ('listeners'). */
class DeviceListeners
{
    /** Whether the device's driver has a listener at all (it may still decline, see forDevice()). */
    public static function supports(Device $device): bool
    {
        return isset(config('devices.listeners', [])[$device->device_driver]);
    }

    public static function for(Device $device): ?DeviceListenerInterface
    {
        $class = config('devices.listeners', [])[$device->device_driver] ?? null;

        return $class ? $class::forDevice($device) : null;
    }
}
