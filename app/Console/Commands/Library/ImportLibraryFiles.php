<?php

namespace App\Console\Commands\Library;

use App\Domain\Storage\LibraryFiles;
use Illuminate\Console\Command;

class ImportLibraryFiles extends Command
{
    protected $signature = 'library:import {--keep : Copy and verify the files but leave them in the inbox}';

    protected $description = 'Move (or with --keep, copy) the audio files in the inbox storage location into the library, organized by artist and album';

    public function handle(LibraryFiles $files): int
    {
        try {
            $this->line('  → '.$files->importInbox($this->option('keep')).' files imported.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
