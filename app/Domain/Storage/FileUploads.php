<?php

namespace App\Domain\Storage;

use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Library\Enrichment;
use App\Jobs\AnalyzeAudioFile;
use App\Models\File;
use App\Models\Media\Track;
use Illuminate\Http\UploadedFile;

/**
 * The one way an uploaded audio file enters the library from the API: the admin upload of the Ripper app
 * (`POST /api/admin/files`) and the node upload of a ripping ReMoment Node (`POST /api/nodes/files`) both end here.
 * Stores the bytes, indexes and places the file (an identical file already in the library is kept instead), then, like
 * the browser upload, queues metadata and analysis for a new track. Throws `InvalidArgumentException` for what is
 * not audio or cannot be read, any other exception for a storage failure.
 */
final class FileUploads
{
    public function __construct(private LibraryFiles $files) {}

    /** @return array{0: File, 1: ?Track} */
    public function ingest(UploadedFile $upload): array
    {
        $stream = fopen($upload->getRealPath(), 'rb');
        try {
            $file = $this->files->upload($upload->getClientOriginalName(), $stream);
        } finally {
            fclose($stream);
        }

        $track = $file->tracks()->first();
        if ($track) {
            Enrichment::queue($track);
        }
        if (AudioAnalysis::enabled()) {
            AnalyzeAudioFile::dispatch($file);
        }

        return [$file, $track];
    }
}
