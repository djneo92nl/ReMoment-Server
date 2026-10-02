<?php

namespace App\Console\Commands;

use App\Domain\Library\Enrichment;
use Illuminate\Console\Command;

class EnrichLibrary extends Command
{
    protected $signature = 'library:enrich {--limit=200 : Maximum number of tracks to queue} {--dry-run : Only count the tracks still missing enrichment}';

    protected $description = 'Queue MusicBrainz, lyrics, Spotify and Last.fm enrichment for tracks that are still missing a source (any library source, newest first)';

    public function handle(): int
    {
        $missing = Enrichment::backlog()->count();

        if ($missing === 0) {
            $this->info('Nothing to enrich.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("{$missing} track(s) are missing at least one source.");

            return self::SUCCESS;
        }

        $queued = Enrichment::queueBacklog(max(1, (int) $this->option('limit')));

        $this->info("Queued enrichment for {$queued} of {$missing} track(s); the rest follows on later runs.");

        return self::SUCCESS;
    }
}
