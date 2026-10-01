<?php

namespace App\Http\Controllers;

use App\Domain\Artwork\SdCardExport;
use App\Jobs\BuildSdCardExport;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin actions for the SD card artwork zip on /settings/clients. */
class ArtworkExportController extends Controller
{
    public function store()
    {
        SdCardExport::markPending();
        BuildSdCardExport::dispatch();

        return back()->with('success', 'Building the SD card artwork zip. Refresh this page in a minute to download it.');
    }

    public function download(): StreamedResponse
    {
        abort_if(SdCardExport::meta() === null, 404);

        return Storage::disk('local')->download(SdCardExport::ZIP_PATH, SdCardExport::DOWNLOAD_NAME, ['Content-Type' => 'application/zip']);
    }
}
