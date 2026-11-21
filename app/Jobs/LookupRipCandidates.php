<?php

namespace App\Jobs;

use App\Domain\Ripper\Rips;
use App\Jobs\Concerns\EnrichesFromSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * The MusicBrainz releases for the disc a ripper node reported (job `ripper.status`), stored on that job's result so the
 * "Rip this disc" card can offer them (docs/architecture/node-ripper.md). Behind the shared `musicbrainz` rate limiter.
 */
class LookupRipCandidates implements ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly string $jobId) {}

    public function middleware(): array
    {
        return [new RateLimited('musicbrainz')];
    }

    public function handle(): void
    {
        Rips::lookup($this->jobId);
    }

    /** MusicBrainz stayed unreachable for every retry: the card still lets the admin pick a release by id. */
    public function failed(\Throwable $e): void
    {
        Rips::lookupFailed($this->jobId, $e->getMessage());
    }
}
