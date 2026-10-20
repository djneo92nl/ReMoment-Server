<?php

namespace App\Events\Device;

use Illuminate\Foundation\Events\Dispatchable;

/** A successful queue edit (add, play next, remove, move, clear) on a device; other browsers re-read the queue. */
class QueueUpdated
{
    use Dispatchable;

    public function __construct(public int $deviceId) {}
}
