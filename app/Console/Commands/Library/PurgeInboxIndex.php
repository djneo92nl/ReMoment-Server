<?php

namespace App\Console\Commands\Library;

use App\Domain\Storage\LibraryFiles;
use App\Models\File;
use App\Models\StorageLocation;
use Illuminate\Console\Command;

class PurgeInboxIndex extends Command
{
    protected $signature = 'library:purge-inbox-index {--dry-run : Only report what would be forgotten}';

    protected $description = 'Forget inbox files an earlier scan indexed (and the tracks only they made); the files on disk stay';

    public function handle(LibraryFiles $files): int
    {
        $inboxIds = StorageLocation::where('role', StorageLocation::ROLE_INBOX)->pluck('id');
        $count = 0;

        File::whereIn('storage_location_id', $inboxIds)->chunkById(200, function ($chunk) use ($files, &$count) {
            foreach ($chunk as $file) {
                $count++;
                if (!$this->option('dry-run')) {
                    $files->forgetFile($file);
                }
            }
        });

        $this->info(($this->option('dry-run') ? 'Would forget ' : 'Forgot ')."{$count} inbox file rows.");

        return self::SUCCESS;
    }
}
