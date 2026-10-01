<?php

namespace App\Integrations\Contracts;

interface ShuffleInterface
{
    /** Turn shuffle on or off for the current playback. */
    public function setShuffle(bool $shuffle): void;
}
