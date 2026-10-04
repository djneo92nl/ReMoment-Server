<?php

namespace App\Integrations\Contracts;

interface QueueJumpInterface
{
    /**
     * Skip ahead in the queue and play from there. 1 is the next track, the
     * first of QueueInterface::getUpNext(); 2 the one after, and so on.
     */
    public function skipToQueuePosition(int $position): void;
}
