<?php

namespace App\Console\Commands\Library;

use App\Domain\Storage\LibraryFiles;
use Illuminate\Console\Command;

class ReorganizeLibraryFiles extends Command
{
    protected $signature = 'library:reorganize {--dry-run : Only list the moves}';

    protected $description = 'Re-apply the library folder layout to the files in the library location';

    public function handle(LibraryFiles $files): int
    {
        try {
            $changes = $files->reorganize((bool) $this->option('dry-run'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($changes as [$file, $path]) {
            $this->line("{$file->path} → {$path}");
        }
        $this->info(count($changes).' file(s) '.($this->option('dry-run') ? 'would move.' : 'moved.'));

        return self::SUCCESS;
    }
}
