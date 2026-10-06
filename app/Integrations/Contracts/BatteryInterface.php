<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\BatteryStatus;

interface BatteryInterface
{
    /** Null when this particular device has no battery (a driver covers mains-powered models too). */
    public function getBattery(): ?BatteryStatus;
}
