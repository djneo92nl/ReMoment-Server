<?php

namespace App\Jobs;

use App\Domain\Health\Heartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Its own queue, polled first by the default worker, so a backlog of real jobs can't make the worker look dead. */
    public function __construct()
    {
        $this->onQueue('health');
    }

    public function handle(): void
    {
        Heartbeat::beat(Heartbeat::QUEUE);
    }
}
