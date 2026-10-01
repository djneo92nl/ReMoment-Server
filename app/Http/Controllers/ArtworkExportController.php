<?php

namespace App\Http\Controllers;

use App\Domain\Artwork\ArtworkBackgrounds;
use App\Domain\Artwork\SdCardExport;
use App\Jobs\BuildSdCardExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin actions for the SD card artwork zips (one per background size) on /settings/clients. */
class ArtworkExportController extends Controller
{
    public function store(Request $request)
    {
        $size = $request->validate([
            'size' => ['nullable', Rule::in(array_keys(ArtworkBackgrounds::SIZES))],
        ])['size'] ?? ArtworkBackgrounds::DEFAULT;

        SdCardExport::markPending($size);
        BuildSdCardExport::dispatch(null, $size);

        return back()->with('success', "Building the {$size} SD card artwork zip. Refresh this page in a minute to download it.");
    }

    public function download(Request $request): StreamedResponse
    {
        $size = (string) $request->query('size', ArtworkBackgrounds::DEFAULT);

        abort_unless(ArtworkBackgrounds::isSize($size) && SdCardExport::meta($size) !== null, 404);

        return Storage::disk('local')->download(SdCardExport::zipPath($size), SdCardExport::downloadName($size), ['Content-Type' => 'application/zip']);
    }
}
