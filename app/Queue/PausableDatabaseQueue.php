<?php

namespace App\Queue;

use Illuminate\Queue\DatabaseQueue;

/** DatabaseQueue that takes no job from a paused queue. */
class PausableDatabaseQueue extends DatabaseQueue
{
    use HonoursQueuePause;

    public function pop($queue = null)
    {
        return $this->pausedQueue($queue) ? null : parent::pop($queue);
    }
}
