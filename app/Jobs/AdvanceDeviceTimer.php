<?php

namespace App\Jobs;

use App\Domain\Scenes\DeviceTimers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Does what is due on a device timer (sleep timer, alarm ramp) and queues its next event; see DeviceTimers. */
class AdvanceDeviceTimer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $timerId) {}

    public function handle(DeviceTimers $timers): void
    {
        $timers->advance($this->timerId);
    }
}
