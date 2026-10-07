<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\MultiRoomStatus;

/** A device that can tell whether it hosts listeners or is joined to a host. Read by its listener. */
interface MultiRoomStatusInterface
{
    public function getMultiRoomStatus(): MultiRoomStatus;
}
