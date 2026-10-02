<?php

namespace App\Console\Commands;

use App\Jobs\EnrichArtistLastfm;
use App\Models\Media\Artist;
use Illuminate\Console\Command;

class BackfillArtistLastfm extends Command
{
    protected $signature = 'library:backfill-lastfm';

    protected $description = 'Queue Last.fm bio/similar-artist enrichment for artists that don\'t have it yet';

    public function handle(): int
    {
        if (!config('lastfm.api_key')) {
            $this->error('LASTFM_API_KEY is not set — add it to .env before running this.');

            return self::FAILURE;
        }

        $artists = Artist::query()
            ->whereDoesntHave('metadata', fn ($q) => $q->where('key', 'enriched_at')->where('source', 'lastfm'))
            ->get();

        if ($artists->isEmpty()) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info("Queuing Last.fm enrichment for {$artists->count()} artist(s)...");
        $bar = $this->output->createProgressBar($artists->count());

        foreach ($artists as $artist) {
            EnrichArtistLastfm::dispatch($artist);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Queued. Bios and similar artists will land as the queue worker processes each job.');

        return self::SUCCESS;
    }
}
