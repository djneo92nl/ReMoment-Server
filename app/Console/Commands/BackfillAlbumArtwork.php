<?php

namespace App\Console\Commands;

use App\Domain\Artwork\ArtworkCache;
use App\Jobs\ProcessArtwork;
use App\Models\Media\Album;
use Illuminate\Console\Command;

class BackfillAlbumArtwork extends Command
{
    protected $signature = 'library:backfill-artwork';

    protected $description = 'Queue color/artwork extraction for album covers with no colors yet, or whose cached proxies are outdated';

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

        if ($urls->isEmpty()) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info("Queuing artwork processing for {$urls->count()} unique cover(s)...");
        $bar = $this->output->createProgressBar($urls->count());

        foreach ($urls as $url) {
            ProcessArtwork::dispatch($url);
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
