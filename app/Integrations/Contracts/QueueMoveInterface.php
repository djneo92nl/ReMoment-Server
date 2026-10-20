<?php

namespace App\Integrations\Contracts;

interface QueueMoveInterface
{
    /**
     * Moves the entry at up-next position `$from` so that it ends up at `$to` (both 1-based, as in
     * QueueInterface::getUpNext()). `$expect` guards against a changed queue, see QueueRemoveInterface.
     */
    public function moveInQueue(int $from, int $to, ?string $expect = null): void;
}
