<?php

namespace App\Console\Commands;

use App\Domain\Library\GenreSync;
use App\Models\Media\Metadata;
use Illuminate\Console\Command;

class SyncGenres extends Command
{
    protected $signature = 'library:sync-genres';

    protected $description = 'Rebuild the canonical genres of artists, albums and tracks from their stored `genres` metadata (run after changing GenreNormalizer)';

    public function handle(): int
    {
        $count = 0;

        Metadata::query()->where('key', 'genres')->with('metadatable')->orderBy('id')->each(function (Metadata $meta) use (&$count) {
            if ($meta->metadatable === null || !method_exists($meta->metadatable, 'genreRelation')) {
                return;
            }

            GenreSync::sync($meta->metadatable, json_decode($meta->value ?? '', true) ?: [], $meta->source);
            $count++;
        });

        $this->info("Synced genres from {$count} metadata row(s).");

        return self::SUCCESS;
    }
}
