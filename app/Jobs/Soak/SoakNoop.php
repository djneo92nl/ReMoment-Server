<?php

namespace App\Jobs\Soak;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/**
 * A job that does nothing useful, for the 24-hour queue soak (`php artisan queue:soak`, docs/architecture/queue-management.md).
 * `kind`: `noop`, `sleep` (a short wait), `memory` (holds a few MB for a moment) or `fail` (throws, to see failed jobs counted).
 * Only the soak command queues it; nothing else in the app does.
 */
class SoakNoop implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    /** Below the ceiling of every worker (history: 60 s). */
    public int $timeout = 60;

    public function __construct(public readonly string $kind = 'noop', public readonly int $param = 0) {}

    public function handle(): void
    {
        match ($this->kind) {
            'sleep' => usleep(max(0, min($this->param, 5000)) * 1000),
            'memory' => strlen(str_repeat('x', max(1, min($this->param, 64)) * 1024 * 1024)),
            'fail' => throw new \RuntimeException('Soak job failing on purpose.'),
            default => null,
        };

        Cache::increment('queue-soak:done');
    }
}
