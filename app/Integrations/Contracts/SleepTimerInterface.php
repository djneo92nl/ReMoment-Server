<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\Settings\SleepTimer;

interface SleepTimerInterface
{
    public function getSleepTimer(): SleepTimer;

    /**
     * Put the device in standby after this many minutes; 0 cancels the timer.
     * Throws UnsupportedOperationException when the device can't be written,
     * InvalidArgumentException outside its range.
     */
    public function setSleepTimer(int $minutes): void;
}
