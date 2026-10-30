<?php

namespace App\Console\Commands\Library;

use App\Domain\Catalogue\CatalogueLinker;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use Illuminate\Console\Command;

class ReconcileCatalogue extends Command
{
    protected $signature = 'catalogue:reconcile {--artist=* : Only these artist ids} {--dry-run : Do everything, then roll it back, and report what would change}';

    protected $description = 'Link library albums to their release-groups and set or clear fulfilled wishes (local queries only)';

    public function handle(): int
    {
        $linked = 0;
        $changed = 0;
        $rows = [];
        $run = function () use (&$linked, &$changed, &$rows) {
            Artist::whereIn('id', CatalogueAlbum::query()->select('artist_id')->distinct())
                ->when($this->option('artist'), fn ($q, $ids) => $q->whereIn('id', $ids))
                ->chunkById(100, function ($artists) use (&$linked, &$changed, &$rows) {
                    foreach ($artists as $artist) {
                        $result = CatalogueLinker::relink($artist);
                        $linked += $result['linked'];
                        $changed += $result['changed'];
                        $rows[] = [$artist->id, $artist->name, $result['linked'], $result['changed'],
                            CatalogueAlbum::where('artist_id', $artist->id)->whereNull('album_id')->count()];
                    }
                });

            // Wished songs: link the library track and close the ones that are owned now (or open them again).
            \App\Models\Media\WantedTrack::query()->orderBy('id')->each(fn ($song) => \App\Domain\Catalogue\WantedTracks::refresh($song));
        };

        if ($this->option('dry-run')) {
            \Illuminate\Support\Facades\DB::beginTransaction();
            try {
                $run();
            } finally {
                \Illuminate\Support\Facades\DB::rollBack();
            }
        } else {
            $run();
        }

        if ($this->option('artist')) {
            $this->table(['Artist', 'Name', 'Linked albums', 'Links changed', 'Missing'], $rows);
        }
        $this->info(($this->option('dry-run') ? '[dry run, nothing saved] ' : '')."{$linked} album(s) linked to the catalogue, {$changed} link(s) made or moved.");

        return self::SUCCESS;
    }
}
