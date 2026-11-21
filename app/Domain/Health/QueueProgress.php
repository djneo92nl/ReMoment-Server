<?php

namespace App\Domain\Health;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * When a job of each queue last finished (processed or failed). The health view and the `queue.stuck` alert use it to tell
 * a backlog that drains (analysis, enrichment) from a queue that stopped: the oldest waiting job alone cannot.
 */
final class QueueProgress
{
    private static function key(string $queue): string
    {
        return "queues:progress:{$queue}";
    }

    public static function record(string $queue): void
    {
        try {
            Cache::put(self::key($queue), now()->timestamp, 7 * 86400);
        } catch (\Throwable) {
            // progress is a hint, never a reason to fail a job
        }
    }

    public static function last(string $queue): ?Carbon
    {
        try {
            $at = Cache::get(self::key($queue));

            return $at ? Carbon::createFromTimestamp((int) $at) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Listener of JobProcessed and JobFailed. */
    public function handle(JobProcessed|JobFailed $event): void
    {
        self::record($event->job->getQueue());
    }
}
