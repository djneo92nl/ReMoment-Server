<?php

namespace App\Console\Commands\Library;

use App\Domain\Storage\StorageTasks;
use App\Models\StorageLocation;
use App\Services\Local\LocalLibraryScanner;
use Illuminate\Console\Command;

class ScanLocalFiles extends Command
{
    protected $signature = 'library:scan-files {location? : Name or id of a storage location (default: all)}
        {--queue : Queue the scans instead of running them here}';

    protected $description = 'Index the audio files of storage locations into the library, streamed by this server';

    public function handle(LocalLibraryScanner $scanner): int
    {
        $arg = $this->argument('location');
        $locations = $arg
            ? StorageLocation::where('id', $arg)->orWhere('name', $arg)->get()
            : StorageLocation::where('role', '!=', StorageLocation::ROLE_INBOX)->get();

        if ($locations->isEmpty()) {
            $this->error($arg ? "No storage location '{$arg}'." : 'No storage locations. Add one first.');

            return self::FAILURE;
        }

        $locations = $locations->filter(fn ($l) => LocalLibraryScanner::scannable($l))->values();
        if ($locations->isEmpty()) {
            $this->error('The inbox is not scanned; its files reach the library with library:import.');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            foreach ($locations as $location) {
                $queued = StorageTasks::queue(StorageTasks::SCAN, $location->id);
                $this->line($queued ? "Queued {$location->name}." : "{$location->name} is already queued or running.");
            }

            return self::SUCCESS;
        }

        foreach ($locations as $location) {
            $this->info("Scanning {$location->name} ({$location->driver})");

            $bar = $this->output->createProgressBar();
            $bar->setFormat(' %current% files — %message%');
            $bar->start();

            $count = $scanner->scan($location, function (int $total, string $file) use ($bar) {
                $bar->setMessage(mb_strimwidth($file, 0, 50, '…'));
                $bar->setProgress($total);
            });

            $bar->finish();
            $this->newLine();
            $this->line("  → {$count} new or changed; ".json_encode($scanner->counts()));
        }

        return self::SUCCESS;
    }
}
