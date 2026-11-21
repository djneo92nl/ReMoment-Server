<?php

namespace App\Queue;

use Illuminate\Queue\RedisQueue;

/** RedisQueue that takes no job from a paused queue; waiting jobs stay where they are, a running job finishes. */
class PausableRedisQueue extends RedisQueue
{
    use HonoursQueuePause;

    public function pop($queue = null, $index = 0)
    {
        return $this->pausedQueue($queue) ? null : parent::pop($queue, $index);
    }
}
