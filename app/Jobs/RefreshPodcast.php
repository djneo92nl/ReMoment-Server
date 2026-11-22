<?php

namespace App\Jobs;

use App\Domain\Podcasts\PodcastRefresher;
use App\Models\Podcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Fetches one podcast feed (workstream 8.2). Queued per feed with a delay between them, so a long list trickles in. */
class RefreshPodcast implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $podcastId, public bool $force = false) {}

    public function handle(): void
    {
        if ($podcast = Podcast::find($this->podcastId)) {
            PodcastRefresher::refresh($podcast, $this->force);
        }
    }
}
