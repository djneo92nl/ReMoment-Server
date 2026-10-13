<?php

namespace App\Jobs;

use App\Domain\Storage\LibraryFiles;
use App\Domain\Storage\StorageTasks;
use App\Models\StorageLocation;
use App\Services\Local\LocalLibraryScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A long file task off the web request: scan a storage location, import the inbox, or reorganize the library
 * (see StorageTasks, docs/architecture/file-storage.md). Its state goes to the cache for the settings page.
 */
class RunStorageTask implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public readonly string $task,
        public readonly ?int $locationId = null,
        public readonly bool $keepSource = false,
    ) {}

    public function uniqueId(): string
    {
        return StorageTasks::id($this->task, $this->locationId);
    }

    public function handle(LocalLibraryScanner $scanner, LibraryFiles $files): void
    {
        StorageTasks::put($this->task, $this->locationId, ['state' => 'running']);

        $message = match ($this->task) {
            StorageTasks::SCAN => $this->scan($scanner),
            StorageTasks::IMPORT => $files->importInbox($this->keepSource).' files imported'.($this->keepSource ? ' (originals kept in the inbox).' : '.'),
            StorageTasks::REORGANIZE => count($files->reorganize()).' files moved.',
            default => throw new \InvalidArgumentException("Unknown storage task {$this->task}."),
        };

        StorageTasks::put($this->task, $this->locationId, ['state' => 'done', 'message' => $message]);
    }

    public function failed(\Throwable $e): void
    {
        StorageTasks::put($this->task, $this->locationId, ['state' => 'failed', 'message' => $e->getMessage()]);
    }

    private function scan(LocalLibraryScanner $scanner): string
    {
        $location = StorageLocation::findOrFail($this->locationId);

        $scanner->scan($location, function (int $handled, string $file) {
            if ($handled % 10 === 1) { // not a cache write per file
                StorageTasks::put($this->task, $this->locationId, ['state' => 'running', 'handled' => $handled, 'current' => $file]);
            }
        });

        $counts = $scanner->counts();

        return sprintf(
            '%d new, %d changed, %d moved, %d unchanged, %d missing.',
            $counts['new'] ?? 0, $counts['updated'] ?? 0, $counts['moved'] ?? 0, $counts['unchanged'] ?? 0, $counts['missing'] ?? 0,
        );
    }
}
