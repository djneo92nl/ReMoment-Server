<?php

namespace App\Queue;

use App\Domain\Health\QueuePause;

/** Hands out no job of a paused queue (App\Domain\Health\QueuePause); everything else is the framework's queue. */
trait HonoursQueuePause
{
    protected function pausedQueue(?string $queue): bool
    {
        return QueuePause::isPaused($queue ?: $this->default);
    }
}
