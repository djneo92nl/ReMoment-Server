<?php

namespace App\Domain\Health;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Restarts a hung default-queue worker. The scheduler dispatches QueueHeartbeat every minute and the
 * worker records it (see Heartbeat); a fresh scheduler beat with a queue beat that stays stale means
 * the worker is stuck (supervisor only restarts one that exited). Killing it makes supervisor start a
 * new one. The analysis worker is left alone: it is busy for a long time by design.
 */
final class QueueWatchdog
{
    public const STALE_AFTER_MINUTES = 5;

    private const COOLDOWN_KEY = 'health:queue-watchdog-cooldown';

    /**
     * @param  Closure():list<int>|null  $findPids  pids of the default queue workers
     * @param  Closure(int):void|null  $kill  stops one of them
     */
    public function __construct(private ?Closure $findPids = null, private ?Closure $kill = null)
    {
        $this->findPids ??= fn () => self::workerPids();
        $this->kill ??= fn (int $pid) => posix_kill($pid, 9);
    }

    /** @return string `ok` | `scheduler-down` | `cooldown` | `no-worker` | `restarted` */
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
