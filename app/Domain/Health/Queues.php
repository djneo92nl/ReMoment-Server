<?php

namespace App\Domain\Health;

use App\Domain\Health\Store\QueueStores;
use App\Domain\Profile\Profile;
use App\Jobs\RunStorageTask;

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
        return (self::worker($queue)['timeout'] ?? 900) + 100;
    }

    /**
     * The supervised worker program that serves a queue (`config('queues.workers')`): `queues`, `timeout`, `stop_wait`.
     * Queue names are matched after the env-configurable ones are resolved (analysis, stems, storage).
     *
     * @return array{queues: list<string>, timeout: int, stop_wait: int, processes: int}|null
     */
    public static function worker(string $queue): ?array
    {
        foreach (config('queues.workers') as $row) {
            if (in_array($queue, self::resolve($row['queues']), true)) {
                return $row;
            }
        }

        return null;
    }

    /** `analysis` and `stems` are env-configurable names: the table lists their defaults. @param list<string> $queues @return list<string> */
    private static function resolve(array $queues): array
    {
        return array_map(fn (string $q) => match ($q) {
            'analysis' => (string) config('analysis.queue'),
            'stems' => self::stems(),
            default => $q,
        }, $queues);
    }

    /** Seconds the oldest waiting job of a queue may wait before it counts as lagging (`config('queues.lag_after')`). */
    public static function lagAfter(string $queue): int
    {
        $table = config('queues.lag_after');
        $key = match (true) {
            $queue === config('analysis.queue') => 'analysis',
            $queue === self::stems() => 'stems',
            default => $queue,
        };

        return (int) ($table[$key] ?? config('queues.default_lag_after'));
    }

    /** Whether a queue may be paused: `health` never is (the watchdog's heartbeat lives there). */
    public static function pausable(string $queue): bool
    {
        return !in_array($queue, config('queues.unpausable'), true);
    }

    /** Heartbeat cache key: the long-standing `health:queue` for the health/default workers. */
    public static function heartbeatKey(string $queue): string
    {
        return $queue === self::HEALTH ? Heartbeat::QUEUE : "health:queue:{$queue}";
    }

    /**
     * Seconds the longest-running job of a queue has been reserved by a worker, null when none (or the driver can't be read).
     */
    public static function longestRunningSeconds(string $queue): ?int
    {
        try {
            return QueueStores::current()?->stats($queue)['longest_running'];
        } catch (\Throwable) {
            return null;
        }
    }
}
