<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\QueueItem;

interface QueueInterface
{
    /**
     * Tracks that will play after the current one, in play order.
     * Returns an empty array when the device isn't playing from a queue
     * (e.g. radio or a line-in stream).
     *
     * @return QueueItem[]
     */
    public function getUpNext(int $limit = 20): array;
}
