<?php

namespace App\Jobs;

use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Library\Enrichment;
use App\Domain\Storage\LibraryFiles;
use App\Domain\Storage\LibraryUploads;
use App\Models\StorageLocation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Takes a file dropped in the browser (already staged in the library's `_uploads/` folder by LibraryUploadController) through
 * parsing its tags, copying it into the library layout and queueing its enrichment, recording each step in
 * LibraryUploads for the table on /settings/storage.
 */
class ProcessLibraryUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $uploadId,
        public readonly int $locationId,
        public readonly string $path,
    ) {}

    public function handle(LibraryFiles $files): void
    {
        $location = StorageLocation::findOrFail($this->locationId);

        LibraryUploads::update($this->uploadId, ['state' => LibraryUploads::PARSING]);
        $file = $files->indexStaged($location, $this->path);

        LibraryUploads::update($this->uploadId, ['state' => LibraryUploads::COPYING]);
        $file = $files->placeInLibrary($file);

        $track = $file->tracks()->with('artist', 'album')->first();

        LibraryUploads::update($this->uploadId, ['state' => LibraryUploads::ENRICHING, 'path' => $file->path, 'track_id' => $track?->id,
            'title' => $track?->name, 'artist' => $track?->artist?->name, 'album' => $track?->album?->name]);

        if ($track) {
            Enrichment::queue($track);
            LibraryUploads::update($this->uploadId, ['sources' => Enrichment::pendingSources($track)]);
        }
        if (AudioAnalysis::enabled()) {
            AnalyzeAudioFile::dispatch($file);
        }

        LibraryUploads::update($this->uploadId, ['queued_at' => now()->toIso8601String()]);
    }

    public function failed(\Throwable $e): void
    {
        LibraryUploads::fail($this->uploadId, $e->getMessage());
    }
}
