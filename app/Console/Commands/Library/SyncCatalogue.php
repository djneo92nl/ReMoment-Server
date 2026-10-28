<?php

namespace App\Console\Commands\Library;

use App\Jobs\SyncArtistCatalogue;
use App\Models\Media\Artist;
use Illuminate\Console\Command;

class SyncCatalogue extends Command
{
    protected $signature = 'catalogue:sync
        {--artist= : Only this artist id}
        {--limit=150 : Maximum number of artists to queue}
        {--force : Also artists that are not due yet}
        {--dry-run : List what would be queued}';

    protected $description = 'Queue the MusicBrainz discography sync for library artists that are due (own-file artists first)';

    public function handle(): int
    {
        $query = Artist::query()->select('artists.*')
            ->leftJoin('catalogue_artists as ca', 'ca.artist_id', '=', 'artists.id')
            ->whereHas('albums');

        if ($id = $this->option('artist')) {
            $query->where('artists.id', (int) $id);
        } elseif (!$this->option('force')) {
            $query->where(fn ($q) => $q->whereNull('ca.next_sync_at')->orWhere('ca.next_sync_at', '<=', now()));
        }

        $artists = $query
            ->orderByRaw("exists (select 1 from tracks where tracks.artist_id = artists.id and tracks.source = 'files') desc")
            ->orderByRaw('artists.favorited_at is null')
            ->orderBy('artists.id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($artists as $artist) {
            $this->line(($this->option('dry-run') ? 'would queue ' : 'queued ').$artist->name);
            if (!$this->option('dry-run')) {
                SyncArtistCatalogue::dispatch($artist);
            }
        }

        $this->info($artists->count().' artist(s) '.($this->option('dry-run') ? 'due' : 'queued').'.');

        return self::SUCCESS;
    }
}
