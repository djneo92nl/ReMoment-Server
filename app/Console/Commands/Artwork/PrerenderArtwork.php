<?php

namespace App\Console\Commands\Artwork;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\PlaylistArtwork;
use App\Jobs\ProcessArtwork;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PrerenderArtwork extends Command
{
    protected $signature = 'artwork:prerender
        {--limit= : Number of most recently played albums (default config artwork.recent_albums)}
        {--max-jobs= : Most jobs to queue this run (default config artwork.prerender_max_jobs)}';

    protected $description = 'Queue artwork processing for playlist covers and recently played albums that lack complete artwork (all proxy sizes and the client backgrounds)';

    /** A cover queued by a previous run is not queued again for this long. */
    private const REQUEUE_AFTER_HOURS = 12;

    public function handle(): int
    {
        $limit = (int) ($this->option('limit') ?? config('artwork.recent_albums'));
        $maxJobs = (int) ($this->option('max-jobs') ?? config('artwork.prerender_max_jobs'));

        // Playlist covers first (one per playlist, so few), then album covers newest first.
        // A composite carries its source covers; it fetches any that aren't processed yet itself.
        $playlists = PlaylistArtwork::all()
            ->reject(fn (array $choice) => ArtworkCache::has($choice['url']));

        $albums = LibraryArtwork::recentCoverUrls($limit)
            ->reject(fn (string $url) => ArtworkCache::has($url) || $playlists->contains('url', $url))
            ->map(fn (string $url) => ['url' => $url, 'sources' => []]);

        $queued = 0;
        foreach ($playlists->concat($albums) as $item) {
            if ($queued >= $maxJobs) {
                break;
            }

            // Skip covers still waiting in the queue from an earlier run.
            if (!Cache::add('artwork:prerender-queued:'.md5($item['url']), true, now()->addHours(self::REQUEUE_AFTER_HOURS))) {
                continue;
            }

            ProcessArtwork::dispatch($item['url'], $item['sources']);
            $queued++;
        }

        $this->info("{$albums->count()} of the last {$limit} played album covers and {$playlists->count()} playlist covers lack complete artwork; queued {$queued} (max {$maxJobs} per run).");

        return self::SUCCESS;
    }
}
