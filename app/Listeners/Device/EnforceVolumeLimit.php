<?php

namespace App\Listeners\Device;

use App\Domain\Device\VolumeLimits;
use App\Events\Device\VolumeUpdated;
use App\Models\Device;

/**
 * A device that reports a volume above its limit (the wheel on the speaker, a remote, its own app) is set back
 * to the limit. The report still reaches the cache and MQTT; the correction arrives as the device's next report.
 */
class EnforceVolumeLimit
{
    public function handle(VolumeUpdated $event): void
    {
        $device = Device::find((int) $event->deviceId);

        if ($device) {
            VolumeLimits::enforce($device, $event->volume);
        }
    }
}
