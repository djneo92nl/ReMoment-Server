<?php

namespace App\Console\Commands\Library;

use App\Domain\Catalogue\CatalogueLinker;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use Illuminate\Console\Command;

class ReconcileCatalogue extends Command
{
    protected $signature = 'catalogue:reconcile';

    protected $description = 'Link library albums to their release-groups and set or clear fulfilled wishes (local queries only)';

    public function handle(): int
    {
        $linked = 0;
        Artist::whereIn('id', CatalogueAlbum::query()->select('artist_id')->distinct())
            ->chunkById(100, function ($artists) use (&$linked) {
                foreach ($artists as $artist) {
                    $linked += CatalogueLinker::linkArtist($artist);
                }
            });

        // Wished songs: link the library track and close the ones that are owned now (or open them again).
        \App\Models\Media\WantedTrack::query()->orderBy('id')->each(fn ($song) => \App\Domain\Catalogue\WantedTracks::refresh($song));

        $this->info("{$linked} album(s) linked to the catalogue.");

        return self::SUCCESS;
    }
}
