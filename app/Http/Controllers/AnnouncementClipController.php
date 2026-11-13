<?php

namespace App\Http\Controllers;

use App\Domain\Playback\AnnouncementClips;

/** An announcement clip for a speaker (`/announce/chime.mp3`), unauthenticated like /stream. See AnnouncementClips. */
class AnnouncementClipController extends Controller
{
    public function show(string $name)
    {
        abort_unless(AnnouncementClips::find($name), 404);

        try {
            $path = AnnouncementClips::file($name);
        } catch (\RuntimeException $e) {
            abort(503, $e->getMessage());
        }
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'audio/mpeg', 'Accept-Ranges' => 'bytes']);
    }
}
