<?php

namespace App\Http\Controllers;

use App\Domain\Storage\FileStore;
use App\Domain\Storage\Proxies;
use App\Models\File;
use App\Models\Media\Track;
use App\Models\TrackFile;
use Illuminate\Http\Request;

/**
 * Serves a track's file to a device, unauthenticated like the REST API. The file is the primary original,
 * or with `?profile=` the proxy of that profile (made on demand, see Proxies; the original is sent until it
 * is ready, so a DLNA renderer that asks for a profile never gets an error). A local file is sent with
 * range support (seek); a remote one is redirected to a short-lived URL when the disk can sign one, else
 * streamed through the server.
 */
class StreamController extends Controller
{
    public function show(Request $request, Track $track, ?string $extension = null)
    {
        $profile = $request->query('profile');

        if (is_string($profile) && Proxies::known($profile)) {
            if ($path = Proxies::readyPath($track, $profile)) {
                return response()->file(Proxies::disk()->path($path), [
                    'Content-Type' => Proxies::mime($profile),
                    'Accept-Ranges' => 'bytes',
                ]);
            }

            Proxies::request($track, $profile);
        }

        $file = $this->fileFor($track, $request->query('profile'));
        abort_if($file === null, 404);

        $store = new FileStore($file->location);
        abort_unless($store->exists($file->path), 404);

        // The DLNA headers are what a DLNA renderer expects of a media server: a byte-seekable stream, not a live one.
        $headers = [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
            'transferMode.dlna.org' => 'Streaming',
            'contentFeatures.dlna.org' => 'DLNA.ORG_OP=01;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000',
        ];

        if ($file->location->isLocal()) {
            return response()->file($store->disk()->path($file->path), $headers);
        }

        try {
            return redirect()->away($store->disk()->temporaryUrl($file->path, now()->addMinutes(30)));
        } catch (\RuntimeException) {
            return $store->disk()->response($file->path, null, $headers);
        }
    }

    private function fileFor(Track $track, ?string $profile): ?File
    {
        $links = $track->trackFiles()->with('file.location')->get()
            ->filter(fn (TrackFile $link) => $link->file?->status === File::PRESENT);

        if ($profile) {
            $proxy = $links->first(fn (TrackFile $link) => $link->role === TrackFile::PROXY && $link->profile === $profile);
            if ($proxy) {
                return $proxy->file;
            }
        }

        return ($links->first(fn (TrackFile $link) => $link->is_primary) ?? $links->first())?->file;
    }
}
