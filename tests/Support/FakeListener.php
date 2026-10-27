<?php

namespace Tests\Support;

use App\Integrations\Contracts\DeviceListenerInterface;
use App\Models\Device;

/** A listener that exists for a device but does nothing (alert tests). */
class FakeListener implements DeviceListenerInterface
{
    public static function forDevice(Device $device): ?static
    {
        return new static;
    }

    public function listen(string $deviceId) {}
}
