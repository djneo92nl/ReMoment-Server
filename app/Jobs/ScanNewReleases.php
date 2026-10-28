<?php

namespace App\Jobs;

use App\Domain\Health\Queues;
use App\Jobs\Concerns\EnrichesFromSource;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;

/** One ListenBrainz fresh-releases request, then a discography sync for every library artist with a release the catalogue lacks. */
class ScanNewReleases implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly int $days = 30)
    {
        $this->onQueue(Queues::CATALOGUE);
    }

    public function uniqueId(): string
    {
        return 'new-releases';
    }

    public function middleware(): array
    {
        return [new RateLimited('listenbrainz')];
    }

    public function handle(): void
    {
        (new \App\Domain\Catalogue\NewReleaseScan)->run($this->days);
    }
}
