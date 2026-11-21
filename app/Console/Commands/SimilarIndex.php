<?php

namespace App\Console\Commands;

use App\Domain\Similar\Embeddings;
use App\Domain\Similar\SqliteVecIndex;
use Illuminate\Console\Command;

class SimilarIndex extends Command
{
    protected $signature = 'similar:index {--status : Only show what is stored and whether the sqlite-vec index matches}';

    protected $description = 'Rebuild the optional sqlite-vec index of the sound embeddings from file_embeddings (similar tracks; the PHP scan needs none, docs/architecture/similar-tracks.md)';

    public function handle(): int
    {
        $index = SqliteVecIndex::configured();
        $stored = Embeddings::count();
        $this->line("Embeddings stored: {$stored} (model ".Embeddings::MODEL.')');
        $this->line('Engine: '.config('similar.engine').'; sqlite-vec library '.($index->available() ? 'loadable' : 'not available (SIMILAR_VEC_EXTENSION, PHP 8.4 Pdo\\Sqlite)'));

        if ($this->option('status')) {
            $this->line('Index rows: '.$index->rowCount().($index->available() && $index->rowCount() !== $stored ? ' (out of date, queries scan the table)' : ''));

            return self::SUCCESS;
        }

        if (!$index->available()) {
            $this->error('The sqlite-vec library is not available: set SIMILAR_VEC_EXTENSION to its absolute path. Queries keep using the PHP scan.');

            return self::FAILURE;
        }

        $this->info('Indexed '.$index->rebuild().' embeddings into '.config('similar.vec_path').'.');

        return self::SUCCESS;
    }
}
