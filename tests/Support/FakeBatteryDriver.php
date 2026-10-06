<?php

namespace Tests\Support;

use App\Domain\Device\BatteryStatus;
use App\Integrations\Contracts\BatteryInterface;

/** A driver covering a battery-capable platform; the model itself may or may not have a battery. */
class FakeBatteryDriver extends FakeBareDriver implements BatteryInterface
{
    public function getBattery(): ?BatteryStatus
    {
        return null;
    }
}
