<?php

namespace App\Console\Commands;

use App\Domain\Library\Enrichment;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use Illuminate\Console\Command;

class EnrichLibrary extends Command
{
    protected $signature = 'library:enrich {--limit=200 : Maximum number of tracks (and of artists and albums) to queue} {--dry-run : Only count the tracks still missing enrichment}';

    protected $description = 'Queue MusicBrainz, lyrics, Spotify and Last.fm enrichment for tracks that are still missing a source (any library source, newest first)';

    public function handle(): int
    {
        $missing = Enrichment::backlog()->count();
        $entities = Enrichment::entityBacklog(Artist::query())->count() + Enrichment::entityBacklog(Album::query())->count();

        if ($missing === 0 && $entities === 0) {
            $this->info('Nothing to enrich.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("{$missing} track(s) are missing at least one source; {$entities} artist(s) and album(s) lack MusicBrainz details.");

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $queued = Enrichment::queueBacklog($limit);
        $queuedEntities = Enrichment::queueEntities($limit);

        $this->info("Queued enrichment for {$queued} of {$missing} track(s) and MusicBrainz details for {$queuedEntities} of {$entities} artist(s) and album(s); the rest follows on later runs.");

        return self::SUCCESS;
    }
}
