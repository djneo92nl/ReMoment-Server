<?php

namespace App\Events\Device;

use App\Domain\Device\MultiRoomStatus;
use Illuminate\Foundation\Events\Dispatchable;

class MultiRoomUpdated
{
    use Dispatchable;

    public function __construct(
        public string $deviceId,
        public MultiRoomStatus $status,
    ) {}
}
