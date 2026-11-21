<?php

namespace App\Http\Controllers\Api;

use App\Domain\Analysis\AnalysisAdmin;
use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Health\QueueInspector;
use App\Http\Controllers\Controller;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Audio analysis for apps (docs/api/admin-analysis.md): the data and buttons of `/settings/analysis`
 * (Domain\Analysis\AnalysisAdmin). Admin token only; `analysis` profile feature.
 */
class AdminAnalysisController extends Controller
{
    public function show(AnalysisAdmin $admin, QueueInspector $inspector): JsonResponse
    {
        return response()->json($admin->toApi($inspector));
    }

    public function problems(Request $request, AnalysisAdmin $admin): JsonResponse
    {
        $data = $request->validate(['stage' => ['nullable', 'string', 'in:'.implode(',', array_keys(AudioAnalysis::stages()))]]);
        $rows = $admin->problemsToApi($data['stage'] ?? '');

        return response()->json(['limit' => AnalysisAdmin::PROBLEM_ROWS, 'data' => $rows]);
    }

    /** Queues one batch of the size the nightly run uses. */
    public function run(AnalysisAdmin $admin): JsonResponse
    {
        if (!AudioAnalysis::enabled()) {
            return $this->off();
        }

        return response()->json(['status' => 'ok', 'queued' => $admin->runBacklog()]);
    }

    public function analyse(AnalysisAdmin $admin, File $file): JsonResponse
    {
        return $admin->analyse($file) ? response()->json(['status' => 'queued', 'file_id' => $file->id]) : $this->off();
    }

    /** Forgets the stage's error and attempts, and queues just that stage again. */
    public function retry(Request $request, AnalysisAdmin $admin, File $file): JsonResponse
    {
        $data = $request->validate(['stage' => ['required', 'string', 'in:'.implode(',', array_keys(AudioAnalysis::stages()))]]);

        if (!$admin->retry($file, $data['stage'])) {
            return response()->json(['error' => 'not_analysed', 'message' => 'This file has no analysis yet, so there is no stage to retry. Use analyse.'], 409);
        }

        return response()->json(['status' => AudioAnalysis::enabled() ? 'queued' : 'cleared', 'file_id' => $file->id, 'stage' => $data['stage']]);
    }

    private function off(): JsonResponse
    {
        return response()->json(['error' => 'analysis_disabled', 'message' => 'Audio analysis is off (ANALYSIS_ENABLED=false).'], 409);
    }
}
