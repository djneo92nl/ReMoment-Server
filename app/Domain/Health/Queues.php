<?php

namespace App\Domain\Health;

use App\Domain\Profile\Profile;
use App\Jobs\RunStorageTask;
use Illuminate\Support\Facades\Queue;

/**
 * The queues the supervised workers listen on, and what is known about each runner.
 * `health` and `default` share the same workers; every other queue has one worker of its own
 * (docker/8.4/supervisord.prod.conf).
 */
final class Queues
{
    public const HEALTH = 'health';

    public const DEFAULT = 'default';

    /** Jobs that run for minutes (SD-card export, uploads, transcodes): kept off the default workers so the heartbeat never waits for them. */
    public const HEAVY = 'heavy';

    /** MusicBrainz discography syncs: short, rate limited jobs; served by the `heavy` worker so no extra process runs on a small board. */
    public const CATALOGUE = 'catalogue';

    /** Stems of one track (Demucs, minutes of CPU): a worker of its own, one job at a time, so it never holds up the analysis queue. */
    public static function stems(): string
    {
        return (string) config('analysis.stems_queue');
    }

    /**
     * Queues that get a heartbeat job every minute: one per worker program the active profile starts (Control has no
     * storage, heavy, analysis or stems worker, so a heartbeat for them would sit unread and raise a false alert;
     * it has the single-writer `history` worker instead).
     *
     * @return list<string>
     */
    public static function heartbeat(): array
    {
        $queues = [self::HEALTH, RunStorageTask::QUEUE, config('analysis.queue'), self::HEAVY, self::stems()];
        if (Profile::is(Profile::CONTROL)) {
            $queues[] = Profile::historyQueue();
        }

        return array_values(array_filter($queues, Profile::runsQueue(...)));
    }

    /** All queues shown on the health page. @return list<string> */
    public static function all(): array
    {
        $queues = [self::HEALTH, self::DEFAULT, RunStorageTask::QUEUE, config('analysis.queue'), self::stems(), config('proxies.queue'), self::HEAVY, self::CATALOGUE, config('queue.connections.redis.queue', 'default')];
        if (Profile::is(Profile::CONTROL)) {
            $queues[] = Profile::historyQueue();
        }

        return array_values(array_unique(array_filter($queues, Profile::runsQueue(...))));
    }

    /** Seconds the longest job of a queue may run (its worker's --timeout), plus a margin: a reserved job older than this means a hung worker. */
    public static function maxRuntime(string $queue): int
    {
        return match ($queue) {
            RunStorageTask::QUEUE => 3600 + 100,
            self::stems() => 3300 + 100,
            default => 900 + 100,
        };
    }

    /** Heartbeat cache key: the long-standing `health:queue` for the health/default workers. */
    public static function heartbeatKey(string $queue): string
    {
        return $queue === self::HEALTH ? Heartbeat::QUEUE : "health:queue:{$queue}";
    }

    /**
     * Seconds the longest-running job of a queue has been reserved by a worker, null when none (or the driver is not Redis).
     * A reserved job's score is its reservation time plus `retry_after`.
     */
    public static function longestRunningSeconds(string $queue): ?int
    {
        try {
            if (config('queue.connections.'.config('queue.default').'.driver') !== 'redis') {
                return null;
            }

            $oldest = Queue::connection()->getConnection()->zrange("queues:{$queue}:reserved", 0, 0, ['withscores' => true]);
            $expires = $oldest ? (int) reset($oldest) : null;

            return $expires === null ? null : max(0, now()->timestamp - ($expires - (int) config('queue.connections.redis.retry_after')));
        } catch (\Throwable) {
            return null;
        }
    }
}
