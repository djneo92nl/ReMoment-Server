<?php

namespace App\Services\Local;

use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Library\Enrichment;
use App\Domain\Storage\CoverExtractor;
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
        $this->counts = [];

        if (!static::scannable($location)) { // the inbox only reaches the library through LibraryFiles::importInbox()
            return 0;
        }

        $store = new FileStore($location);
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

        // Albums indexed before covers were read, or whose picture was missing then.
        app(CoverExtractor::class)->backfill();

        $location->update(['last_scanned_at' => now()]);
        Enrichment::queueBacklog(Enrichment::SCAN_BATCH);
        AudioAnalysis::queueBacklog((int) config('analysis.scan_batch'));

        return $handled;
    }

    /** Inbox files are indexed only while they are imported, so they never show up in the library before. */
    public static function scannable(StorageLocation $location): bool
    {
        return $location->role !== StorageLocation::ROLE_INBOX;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }
}
