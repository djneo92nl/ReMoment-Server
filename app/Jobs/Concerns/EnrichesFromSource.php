<?php

namespace App\Jobs\Concerns;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Shared queue behaviour of the per-source enrichment jobs: one job per record at a time, retried when
 * the service throws (rate limit, outage) but not endlessly, and dropped when a merge removed the record.
 */
trait EnrichesFromSource
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    /** Releases by a rate limiter don't count as attempts; only thrown exceptions do. */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    /** Seconds the uniqueness lock is held at most, should a job die without releasing it. */
    public int $uniqueFor = 86400;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }
}
