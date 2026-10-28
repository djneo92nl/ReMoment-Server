<?php

namespace App\Console\Commands\Library;

use App\Domain\Catalogue\NewReleaseScan;
use App\Jobs\ScanNewReleases;
use Illuminate\Console\Command;

class ScanNewReleasesCommand extends Command
{
    protected $signature = 'catalogue:new-releases
        {--days=30 : Days of the ListenBrainz fresh-releases feed to read (1-90)}
        {--dry-run : Request the feed now and list the artists that would be synced}';

    protected $description = 'Find new releases of the library artists (ListenBrainz feed) and sync their MusicBrainz discography at once';

    public function handle(): int
    {
        $days = max(1, min(90, (int) $this->option('days')));

        if (!$this->option('dry-run')) {
            ScanNewReleases::dispatch($days);
            $this->info('Queued.');

            return self::SUCCESS;
        }

        $artists = (new NewReleaseScan)->run($days, dispatch: false);
        foreach ($artists as $artist) {
            $this->line('would sync '.$artist->name);
        }
        $this->info(count($artists).' artist(s) with a release the catalogue lacks.');

        return self::SUCCESS;
    }
}
