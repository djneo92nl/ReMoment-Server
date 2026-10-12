<?php

namespace App\Console\Commands\Library;

use App\Domain\Storage\LibraryFiles;
use Illuminate\Console\Command;

class ImportLibraryFiles extends Command
{
    protected $signature = 'library:import';

    protected $description = 'Move the audio files in the inbox storage location into the library, organized by artist and album';

    public function handle(LibraryFiles $files): int
    {
        try {
            $this->line('  → '.$files->importInbox().' files imported.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
