<?php

namespace App\Console\Commands\Library;

use App\Domain\Concerts\Concerts;
use App\Domain\Concerts\ConcertSettings;
use App\Jobs\SyncArtistConcerts;
use Illuminate\Console\Command;

class SyncConcerts extends Command
{
    protected $signature = 'concerts:sync
        {--limit=100 : Most artists to queue (the ones not read in the last --days days, a MusicBrainz request a second)}
        {--days=7 : Read an artist again after this many days}
        {--dry-run : Only count the artists that are due}';

    protected $description = 'Queue the MusicBrainz event lookups of library artists (opt-in setting) and drop concerts that have happened';

    public function handle(): int
    {
        $pruned = Concerts::prune();

        if (!ConcertSettings::enabled()) {
            $this->info('Concerts are switched off (Settings › Library › Concerts); nothing queued.');

            return self::SUCCESS;
        }

        $due = Concerts::dueArtists(max(1, (int) $this->option('limit')), max(1, (int) $this->option('days')))->get();
        $this->info("{$due->count()} artists due, {$pruned} past concerts removed.");

        if (!$this->option('dry-run')) {
            $due->each(fn ($artist) => SyncArtistConcerts::dispatch($artist));
        }

        return self::SUCCESS;
    }
}
