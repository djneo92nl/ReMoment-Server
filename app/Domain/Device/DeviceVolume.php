<?php

namespace App\Domain\Device;

use App\Domain\Device\Cache\Volume;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;

/** Sets a device's volume (0-100) and records it right away, instead of waiting for its listener to see it. */
final class DeviceVolume
{
    /**
     * @return int the level that was set; null when the device has no volume control
     *
     * @throws \Throwable when the device does not answer
     */
    public static function set(Device $device, int $volume): ?int
    {
        $driver = $device->driver;

        if (!($driver instanceof VolumeControlInterface)) {
            return null;
        }

        $volume = max(0, min(100, $volume));
        $driver->setVolume($volume);
        Volume::updateVolume($device->id, $volume);

        return $volume;
    }
}
