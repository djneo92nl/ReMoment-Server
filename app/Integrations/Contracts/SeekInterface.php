<?php

namespace App\Integrations\Contracts;

interface SeekInterface
{
    /** Jump to an absolute position (in seconds) within the current track. */
    public function seek(int $seconds): void;
}
