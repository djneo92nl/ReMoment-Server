<?php

namespace App\Integrations\Contracts;

interface QueueRemoveInterface
{
    /**
     * Removes the entry `$position` places ahead (1 = the first of QueueInterface::getUpNext()).
     * `$expect` is the uri the caller saw at that position: when the queue has changed since, the driver
     * throws an \InvalidArgumentException instead of removing the wrong entry.
     */
    public function removeFromQueue(int $position, ?string $expect = null): void;
}
