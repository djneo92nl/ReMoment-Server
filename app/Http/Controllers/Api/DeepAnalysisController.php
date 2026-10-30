<?php

namespace App\Http\Controllers\Api;

use App\Domain\Analysis\DeepAnalysis;
use App\Http\Controllers\Controller;
use App\Models\Media\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Deep analysis of one track on request (sections now); admin token. See docs/api/deep-analysis.md. */
class DeepAnalysisController extends Controller
{
    public function store(Request $request, Track $track): JsonResponse
    {
        $data = $request->validate(['kinds' => 'required|array|min:1|max:10', 'kinds.*' => 'required|string']);

        if ($error = $this->unknownKind($data['kinds'])) {
            return $error;
        }
        if (($file = DeepAnalysis::fileFor($track)) === null) {
            return $this->noFile();
        }

        $result = DeepAnalysis::request($file, $data['kinds']);
        $estimate = ['sections_seconds' => DeepAnalysis::estimateSeconds('sections', $track->duration)];

        return response()->json($result + ['estimate' => $estimate], match ($result['status']) {
            'queued' => 202,
            'not_available' => 409,
            default => 200,
        });
    }

    public function show(Track $track): JsonResponse
    {
        if (($file = DeepAnalysis::fileFor($track)) === null) {
            return $this->noFile();
        }

        $analysis = $file->analysis()->first();
        $kinds = [];
        foreach (DeepAnalysis::kinds() as $kind) {
            $kinds[$kind] = DeepAnalysis::state($file, $analysis, $kind);
        }

        return response()->json(['track_id' => $track->id, 'available' => DeepAnalysis::anyAvailable(), 'kinds' => $kinds]);
    }

    public function destroy(Track $track, string $kind): JsonResponse
    {
        if ($error = $this->unknownKind([$kind])) {
            return $error;
        }
        if (($file = DeepAnalysis::fileFor($track)) === null) {
            return $this->noFile();
        }

        return response()->json(['status' => 'ok', 'removed' => DeepAnalysis::forget($file, $kind)]);
    }

    /** @param  list<string>  $kinds */
    private function unknownKind(array $kinds): ?JsonResponse
    {
        $unknown = array_values(array_diff($kinds, DeepAnalysis::kinds()));

        return $unknown === [] ? null : response()->json([
            'error' => 'unknown_kind',
            'message' => 'Unknown kind '.implode(', ', $unknown).'. Known: '.implode(', ', DeepAnalysis::kinds()).'.',
        ], 422);
    }

    private function noFile(): JsonResponse
    {
        return response()->json([
            'error' => 'no_file',
            'message' => 'This track has no audio file of its own on this server, so there is nothing to analyse.',
        ], 422);
    }
}
