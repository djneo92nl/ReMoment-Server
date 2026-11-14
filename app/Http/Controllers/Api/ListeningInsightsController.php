<?php

namespace App\Http\Controllers\Api;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibrarySources;
use App\Domain\Stats\ListeningInsights;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/library/insights: skips, new against repeat, mood and tempo by part of the day, late listening (docs/api/insights.md). */
class ListeningInsightsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:'.ListeningInsights::MAX_DAYS],
            'client' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:'.implode(',', LeadingSource::apiScopes())],
        ]);

        $client = $request->filled('client') ? Client::byApiToken((string) $request->query('client')) : null;
        $hidden = LibrarySources::hiddenForRequest($client, $request->query('scope'));

        return response()->json(ListeningInsights::forApi($request->integer('days', ListeningInsights::DEFAULT_DAYS), $hidden));
    }
}
