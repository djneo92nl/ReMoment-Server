<?php

namespace App\Http\Controllers;

use App\Models\Media\Track;
use App\Services\Local\LocalLibraryScanner;

/**
 * Serves a scanned file to a device, unauthenticated like the REST API. BinaryFileResponse answers range
 * requests (seek) and sets Content-Length; speakers pull the audio from here.
 */
class StreamController extends Controller
{
    public function show(Track $track)
    {
        $meta = $track->metadata()->where('source', LocalLibraryScanner::FILE_SOURCE)->whereIn('key', ['file_path', 'mime_type'])->pluck('value', 'key');
        $path = $meta['file_path'] ?? null;

        abort_unless($path !== null && is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => $meta['mime_type'] ?? 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
