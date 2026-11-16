<?php

namespace App\Http\Controllers\Api;

use App\Domain\Analysis\Camelot;
use App\Domain\Analysis\DeepAnalysis;
use App\Domain\Analysis\Stages\StemsStage;
use App\Domain\Analysis\StemAudio;
use App\Http\Controllers\Controller;
use App\Models\Media\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The kept stem audio of one track, for the apps' mixer: the four files as signed URLs (admin token to list, the
 * signature is the authorization to download) with what a mixer needs to line tracks up (bpm, key, Camelot code).
 * The files exist only after a `stem_audio` deep analysis was asked for. See docs/api/stems.md.
 */
class TrackStemsController extends Controller
{
    public function show(Track $track): JsonResponse
    {
        if (($file = DeepAnalysis::fileFor($track)) === null) {
            return response()->json(['error' => 'no_file', 'message' => 'This track has no audio file of its own on this server, so it has no stems.'], 422);
        }

        $analysis = $file->analysis()->first();
        $state = DeepAnalysis::state($file, $analysis, 'stem_audio');

        if ($state['state'] !== 'done') {
            return response()->json([
                'error' => 'not_ready',
                'state' => $state['state'],
                'message' => 'The stems of this track are not kept (state: '.$state['state'].'). Ask for kind stem_audio with POST /api/library/tracks/{id}/deep-analysis.',
            ], 404);
        }

        if (!StemAudio::complete($file, $analysis)) {
            return response()->json([
                'error' => 'files_missing',
                'message' => 'The kept stem files are gone from the disk. Remove kind stem_audio with DELETE /api/library/tracks/{id}/deep-analysis/stem_audio and ask for it again.',
            ], 404);
        }

        $manifest = $analysis->stem_audio;
        $ttl = max(60, (int) config('analysis.stem_audio_url_ttl'));
        $stems = [];
        foreach (StemsStage::LANES as $stem) {
            $stems[$stem] = [
                'url' => URL::temporarySignedRoute('api.library.stems.download', now()->addSeconds($ttl), ['track' => $track->id, 'stem' => $stem], absolute: false),
                'bytes' => $manifest['files'][$stem]['bytes'],
            ];
        }

        return response()->json([
            'track_id' => $track->id,
            'format' => $manifest['format'],
            'mime' => StemAudio::mime($manifest['format']),
            'bitrate' => $manifest['bitrate'],
            'sample_rate' => $manifest['sample_rate'],
            'channels' => $manifest['channels'],
            'duration' => $manifest['duration'],
            'bytes' => $manifest['bytes'],
            'expires_in' => $ttl,
            'bpm' => $analysis->bpm !== null ? round((float) $analysis->bpm, 2) : null,
            'key' => $analysis->key,
            'mode' => $analysis->mode,
            'camelot' => Camelot::code($analysis->key, $analysis->mode),
            'stems' => $stems,
        ]);
    }

    /** The signed, temporary download of one stem; BinaryFileResponse answers Range requests by itself. */
    public function download(Track $track, string $stem): BinaryFileResponse|JsonResponse
    {
        $file = in_array($stem, StemsStage::LANES, true) ? DeepAnalysis::fileFor($track) : null;
        $analysis = $file?->analysis()->first();
        $manifest = $analysis?->stem_audio;
        $name = $manifest['files'][$stem]['name'] ?? null;

        if ($file === null || $name === null || !StemAudio::complete($file, $analysis)) {
            return response()->json(['error' => 'not_found', 'message' => 'This stem is not available (not kept, removed or the file changed).'], 404);
        }

        return response()->file(StemAudio::disk()->path(StemAudio::path($file, $name)), [
            'Content-Type' => StemAudio::mime($manifest['format']),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
