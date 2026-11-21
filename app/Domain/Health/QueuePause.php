<?php

namespace App\Domain\Health;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Which queues are paused (docs/architecture/queue-management.md). The list lives in the setting `queues_paused`
 * (it survives a restart and a cache flush) with a cache copy the workers read before every pop, so a pause or
 * resume takes effect on the next poll of every supervised worker, without restarting any. The queue classes in
 * App\Queue refuse to hand out jobs of a paused queue; a job already running finishes.
 */
final class QueuePause
{
    private const SETTING = 'queues_paused';

    private const CACHE = 'queues:paused';

    /** @return list<string> */
    public static function all(): array
    {
        try {
            $paused = Cache::get(self::CACHE);
            if (!is_array($paused)) {
                $paused = json_decode((string) Setting::get(self::SETTING, '[]'), true);
                $paused = is_array($paused) ? array_values($paused) : [];
                Cache::forever(self::CACHE, $paused);
            }

            return $paused;
        } catch (\Throwable) {
            return [];   // a worker must never stop because the pause list can't be read
        }
    }

    public static function isPaused(string $queue): bool
    {
        return in_array($queue, self::all(), true);
    }

    /** @throws \InvalidArgumentException for a queue that can't be paused or isn't one of the app's */
    public static function pause(string $queue): void
    {
        if (!Queues::pausable($queue)) {
            throw new \InvalidArgumentException("The {$queue} queue cannot be paused: the watchdog's heartbeat runs on it.");
        }
        if (!in_array($queue, Queues::all(), true)) {
            throw new \InvalidArgumentException("Unknown queue {$queue}.");
        }

        self::store(array_values(array_unique([...self::all(), $queue])));
    }

    public static function resume(string $queue): void
    {
        self::store(array_values(array_diff(self::all(), [$queue])));
    }

    /** @param list<string> $paused */
    private static function store(array $paused): void
    {
        Setting::set(self::SETTING, json_encode($paused));
        Cache::forever(self::CACHE, $paused);
    }
}
