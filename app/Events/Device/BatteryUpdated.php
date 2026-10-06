<?php

namespace App\Events\Device;

use App\Domain\Device\BatteryStatus;
use Illuminate\Foundation\Events\Dispatchable;

class BatteryUpdated
{
    use Dispatchable;

    public function __construct(
        public string $deviceId,
        public BatteryStatus $battery,
    ) {}
}
