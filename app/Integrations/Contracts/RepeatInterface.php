<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\RepeatMode;

interface RepeatInterface
{
    /** Set the repeat mode: off, all (the queue/context) or one (the current track). */
    public function setRepeat(RepeatMode $mode): void;
}
