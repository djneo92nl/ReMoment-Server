<?php

namespace App\Console\Commands;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\PlaylistArtwork;
use App\Jobs\ProcessArtwork;
use App\Models\Media\Album;
use Illuminate\Console\Command;

class BackfillAlbumArtwork extends Command
{
    protected $signature = 'library:backfill-artwork';

    protected $description = 'Queue color/artwork extraction for album covers with no colors yet, or whose cached proxies are outdated, and for playlist covers that are missing or outdated';

    public function handle(): int
    {
        // Besides covers without colors, re-queue covers whose cache entry
        // predates a proxy size (e.g. proxy_bg). Expired entries are left alone,
        // as before: they are regenerated on next play.
        $urls = Album::query()
            ->whereNotNull('images')
            ->get(['images', 'colors'])
            ->map(fn (Album $album) => [
                'url' => $this->coverUrl($album->images ?? []),
                'has_colors' => !empty($album->colors),
            ])
            ->filter(fn (array $row) => $row['url'] !== null)
            ->filter(fn (array $row) => !$row['has_colors'] || $this->isOutdated($row['url']))
            ->pluck('url')
            ->unique()
            ->values();

        // Playlist covers (composites included) that are missing or outdated;
        // a composite's URL changes with its covers, so a changed playlist shows up here too.
        $playlists = PlaylistArtwork::all()
            ->reject(fn (array $choice) => ArtworkCache::has($choice['url']) || $urls->contains($choice['url']));

        $jobs = $urls->map(fn (string $url) => ['url' => $url, 'sources' => []])->concat($playlists);

        if ($jobs->isEmpty()) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info("Queuing artwork processing for {$jobs->count()} unique cover(s)...");
        $bar = $this->output->createProgressBar($jobs->count());

        foreach ($jobs as $job) {
            ProcessArtwork::dispatch($job['url'], $job['sources']);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Queued. Colors will land on album rows as the queue worker processes each job.');

        return self::SUCCESS;
    }

    private function isOutdated(string $url): bool
    {
        $entry = ArtworkCache::get($url);

        return is_array($entry) && !ArtworkCache::isComplete($entry);
    }

    private function coverUrl(array $images): ?string
    {
        $first = $images[0] ?? null;

        return (is_array($first) ? ($first['url'] ?? null) : $first) ?: null;
    }
}
