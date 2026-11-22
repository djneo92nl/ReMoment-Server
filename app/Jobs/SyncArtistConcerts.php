<?php

namespace App\Jobs;

use App\Domain\Concerts\Concerts;
use App\Domain\Concerts\ConcertSettings;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Artist;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;

/** One artist's upcoming MusicBrainz events (roadmap 12.6). Uses the `musicbrainz` limiter; a rate limit or outage is retried. */
class SyncArtistConcerts implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Artist $artist) {}

    public function uniqueId(): string
    {
        return 'concerts-'.$this->artist->id;
    }

    public function middleware(): array
    {
        return [new RateLimited('musicbrainz')];
    }

    public function handle(): void
    {
        if (!ConcertSettings::enabled()) {
            return;   // switched off while the job waited
        }

        Concerts::sync($this->artist);
    }
}
