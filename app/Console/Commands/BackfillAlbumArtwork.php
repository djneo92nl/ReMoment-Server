<?php

namespace App\Console\Commands;

use App\Jobs\ProcessArtwork;
use App\Models\Media\Album;
use Illuminate\Console\Command;

class BackfillAlbumArtwork extends Command
{
    protected $signature = 'library:backfill-artwork';

    protected $description = 'Queue color/artwork extraction for albums that have cover art but no cached colors yet';

    public function handle(): int
    {
        $urls = Album::query()
            ->whereNotNull('images')
            ->whereNull('colors')
            ->pluck('images')
            ->map(fn (array $images) => is_array($images[0] ?? null) ? ($images[0]['url'] ?? null) : ($images[0] ?? null))
            ->filter()
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
}
