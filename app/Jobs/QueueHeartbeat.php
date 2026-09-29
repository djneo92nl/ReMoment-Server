<?php

namespace App\Jobs;

use App\Domain\Health\Heartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Heartbeat::beat(Heartbeat::QUEUE);
    }
}
