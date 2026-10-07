<?php

namespace App\Domain\Device\Settings;

/** Minutes until the device goes to standby; 0 means no timer is running. */
final readonly class SleepTimer
{
    public function __construct(
        public AdjustmentRange $minutes,
        public bool $writable = true,
    ) {}

    public function toArray(): array
    {
        return [...$this->minutes->toArray(), 'writable' => $this->writable];
    }
}
