<?php

namespace App\Domain\Health;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Timestamps written by background processes so the health page can tell
 * whether they're alive. The scheduler beats every minute and dispatches
 * QueueHeartbeat, so a stale queue beat with a fresh scheduler beat means
 * no queue worker is running.
 */
final class Heartbeat
{
    public const SCHEDULER = 'health:scheduler';

    public const QUEUE = 'health:queue';

    public static function beat(string $key): void
    {
        Cache::put($key, now()->toIso8601String(), 86400);
    }

    public static function last(string $key): ?Carbon
    {
        $value = Cache::get($key);

        return $value ? Carbon::parse($value) : null;
    }
}
