<?php

namespace App\Integrations\Common;

/** The pause a device listener takes before reconnecting: 1 s after a clean end, doubling per failure up to a cap. */
final class ListenerBackoff
{
    private int $delay = 1;

    public function __construct(private readonly int $maxSeconds = 30) {}

    /** Seconds to wait before the next attempt; `$failed` is whether the last one ended in an error. */
    public function next(bool $failed): int
    {
        $this->delay = $failed ? min($this->delay * 2, $this->maxSeconds) : 1;

        return $this->delay;
    }
}
