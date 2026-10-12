<?php

namespace App\Services\Local;

use App\Domain\Library\Enrichment;
use App\Domain\Storage\FileIndexer;
use App\Domain\Storage\FileStore;
use App\Models\File;
use App\Models\StorageLocation;

/**
 * Indexes the audio files of a storage location into the library through FileIndexer, the way
 * DlnaLibraryScanner does for a DLNA server, and marks files that disappeared as missing.
 */
class LocalLibraryScanner
{
    /** @var array<string, int> outcome => count of the last scan */
    private array $counts = [];

    public function __construct(private FileIndexer $indexer) {}

    /**
     * @param  callable(int $handled, string $path)|null  $progress
     * @return int files added or changed (unchanged ones are not counted)
     */
    public function scan(StorageLocation $location, ?callable $progress = null): int
    {
        $store = new FileStore($location);
        $this->counts = [];
        $seen = [];
        $handled = 0;

        foreach ($store->files() as $path => $_) {
            [$file, $outcome] = $this->indexer->index($location, $path, $store);
            $this->counts[$outcome] = ($this->counts[$outcome] ?? 0) + 1;

            if ($file) {
                $seen[] = $file->id;
            }
            if ($file && $outcome !== FileIndexer::UNCHANGED) {
                $handled++;
                if ($progress) {
                    ($progress)($handled, basename($path));
                }
            }
        }

        $missing = $location->files()->where('status', File::PRESENT)->whereNotIn('id', $seen)->update(['status' => File::MISSING]);
        $this->counts['missing'] = $missing;

        $location->update(['last_scanned_at' => now()]);
        Enrichment::queueBacklog(Enrichment::SCAN_BATCH);

        return $handled;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }
}
