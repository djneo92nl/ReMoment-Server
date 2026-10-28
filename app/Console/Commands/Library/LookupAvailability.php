<?php

namespace App\Console\Commands\Library;

use App\Domain\Catalogue\SpotifyAvailability;
use App\Jobs\LookupSpotifyAvailability;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use Illuminate\Console\Command;

class LookupAvailability extends Command
{
    protected $signature = 'catalogue:availability
        {--limit=100 : Maximum number of artists to queue}
        {--dry-run : Only list the artists}';

    protected $description = 'Queue the Spotify availability lookup for artists with release-groups still unanswered (wished albums first)';

    public function handle(SpotifyAvailability $spotify): int
    {
        if (!$spotify->enabled()) {
            $this->warn('Spotify is not connected: nothing to look up.');

            return self::SUCCESS;
        }

        $wished = CatalogueAlbum::whereNotNull('wanted_at')->pluck('artist_id')->unique()->all();
        $ids = collect($wished)->merge(CatalogueAlbum::whereNull('ignored_at')->distinct()->pluck('artist_id'))->unique();

        $queued = 0;
        foreach ($ids as $id) {
            if ($queued >= max(1, (int) $this->option('limit'))) {
                break;
            }
            $artist = Artist::find($id);
            if ($artist === null || $spotify->staleRows($artist)->isEmpty()) {
                continue;
            }
            $this->line(($this->option('dry-run') ? 'would queue ' : 'queued ').$artist->name);
            if (!$this->option('dry-run')) {
                LookupSpotifyAvailability::dispatch($artist);
            }
            $queued++;
        }

        $this->info("{$queued} artist(s) ".($this->option('dry-run') ? 'due' : 'queued').'.');

        return self::SUCCESS;
    }
}
