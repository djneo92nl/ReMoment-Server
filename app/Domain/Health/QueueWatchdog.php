<?php

namespace App\Domain\Health;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Restarts a hung default-queue worker. The scheduler dispatches QueueHeartbeat every minute and the
 * worker records it (see Heartbeat); a fresh scheduler beat with a queue beat that stays stale means
 * the worker is stuck (supervisor only restarts one that exited). Killing it makes supervisor start a
 * new one. The analysis worker is left alone: it is busy for a long time by design. A worker that is
 * merely busy with a long default-queue job (an SD-card export, a proxy transcode) is left alone too, until
 * that job has run longer than any job's timeout.
 */
final class QueueWatchdog
{
    public const STALE_AFTER_MINUTES = 5;

    /** Seconds a default-queue job may run before the worker counts as hung: the longest job timeout (900) and some margin. */
    public const BUSY_GRACE_SECONDS = 1000;

    private const COOLDOWN_KEY = 'health:queue-watchdog-cooldown';

    /**
     * @param  Closure():list<int>|null  $findPids  pids of the default queue workers
     * @param  Closure(int):void|null  $kill  stops one of them
     * @param  Closure():?int|null  $runningSeconds  how long the longest-running default-queue job has been reserved, null when none
     */
    public function __construct(private ?Closure $findPids = null, private ?Closure $kill = null, private ?Closure $runningSeconds = null)
    {
        $this->runningSeconds ??= fn () => self::longestRunningSeconds();
        $this->findPids ??= fn () => self::workerPids();
        $this->kill ??= fn (int $pid) => posix_kill($pid, 9);
    }

    /** @return string `ok` | `scheduler-down` | `busy` | `cooldown` | `no-worker` | `restarted` */
    public function run(): string
    {
        $scheduler = Heartbeat::last(Heartbeat::SCHEDULER);
        if (!$scheduler || $scheduler->lt(now()->subMinutes(2))) {
            return 'scheduler-down';   // no heartbeat job was dispatched, so a stale queue beat proves nothing
        }

        $queue = Heartbeat::last(Heartbeat::QUEUE);
        if ($queue && $queue->gte(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
            return 'ok';
        }

        $running = ($this->runningSeconds)();
        if ($running !== null && $running < self::BUSY_GRACE_SECONDS) {
            return 'busy';   // a long job holds every worker; killing them would lose it
        }

        // Give a freshly restarted worker time to catch up before judging it again.
        if (!Cache::add(self::COOLDOWN_KEY, true, 300)) {
            return 'cooldown';
        }

        $pids = ($this->findPids)();
        if ($pids === []) {
            return 'no-worker';   // supervisor is (re)starting it, or it is not running at all
        }

        foreach ($pids as $pid) {
            ($this->kill)($pid);
        }
        Log::warning('Queue worker did not run its heartbeat; killed it so supervisor restarts it.', ['pids' => $pids]);

        return 'restarted';
    }

    /** Redis only: a reserved job's score is its reservation time plus `retry_after`. */
    private static function longestRunningSeconds(): ?int
    {
        try {
            if (config('queue.connections.'.config('queue.default').'.driver') !== 'redis') {
                return null;
            }

            $oldest = Queue::connection()->getConnection()->zrange('queues:default:reserved', 0, 0, ['withscores' => true]);
            $expires = $oldest ? (int) reset($oldest) : null;

            return $expires === null ? null : max(0, now()->timestamp - ($expires - (int) config('queue.connections.redis.retry_after')));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<int> */
    private static function workerPids(): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $cmd = str_replace("\0", ' ', (string) @file_get_contents($file));
            if (str_contains($cmd, 'artisan queue:work') && str_contains($cmd, '--queue=health,default')) {
                $pids[] = (int) basename(dirname($file));
            }
        }

        return $pids;
    }
}
