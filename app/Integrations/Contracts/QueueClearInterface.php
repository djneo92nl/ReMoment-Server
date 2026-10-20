<?php

namespace App\Integrations\Contracts;

interface QueueClearInterface
{
    /** Empties the queue after the current track; what is playing keeps playing. */
    public function clearQueue(): void;
}
