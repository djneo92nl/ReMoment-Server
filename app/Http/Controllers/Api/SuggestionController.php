<?php

namespace App\Http\Controllers\Api;

use App\Domain\Health\LibraryReports;
use App\Domain\Health\Suggestions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Accept or dismiss one entry of the `suggestions` health report (docs/api/suggestions.md). The list itself is
 * `GET /api/library/health/suggestions`. Admin token: accepting rewrites a file's tags.
 */
class SuggestionController extends Controller
{
    public function accept(string $id): JsonResponse
    {
        try {
            $result = Suggestions::accept($id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'storage_error', 'message' => $e->getMessage()], 502);
        }

        LibraryReports::forget();

        return response()->json($result);
    }

    public function dismiss(string $id): JsonResponse
    {
        try {
            Suggestions::dismiss($id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        }

        LibraryReports::forget();

        return response()->json(['status' => 'dismissed', 'id' => $id]);
    }
}
