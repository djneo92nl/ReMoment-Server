<?php

namespace App\Console\Commands;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LibraryArtwork;
use App\Jobs\ProcessArtwork;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PrerenderArtwork extends Command
{
    protected $signature = 'artwork:prerender
        {--limit= : Number of most recently played albums (default config artwork.recent_albums)}
        {--max-jobs= : Most jobs to queue this run (default config artwork.prerender_max_jobs)}';

    protected $description = 'Queue artwork processing for recently played albums that lack complete artwork (all proxy sizes and the client background)';

    /** A cover queued by a previous run is not queued again for this long. */
    private const REQUEUE_AFTER_HOURS = 12;

    public function handle(): int
    {
        $limit = (int) ($this->option('limit') ?? config('artwork.recent_albums'));
        $maxJobs = (int) ($this->option('max-jobs') ?? config('artwork.prerender_max_jobs'));

        $missing = LibraryArtwork::recentCoverUrls($limit)
            ->reject(fn (string $url) => ArtworkCache::has($url));

        $queued = 0;
        foreach ($missing as $url) {
            if ($queued >= $maxJobs) {
                break;
            }

            // Skip covers still waiting in the queue from an earlier run.
            if (!Cache::add('artwork:prerender-queued:'.md5($url), true, now()->addHours(self::REQUEUE_AFTER_HOURS))) {
                continue;
            }

            ProcessArtwork::dispatch($url);
            $queued++;
        }

        $this->info("{$missing->count()} of the last {$limit} played album covers lack complete artwork; queued {$queued} (max {$maxJobs} per run).");

        return self::SUCCESS;
    }
}
