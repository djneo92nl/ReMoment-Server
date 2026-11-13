<?php

namespace App\Http\Controllers\Api;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibrarySources;
use App\Domain\Stats\ListeningStats;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** GET /api/library/stats: the listening statistics of the household (docs/api/stats.md). */
class ListeningStatsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'client' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:'.implode(',', LeadingSource::apiScopes())],
        ]);

        $range = ListeningStats::range($request->query('from'), $request->query('to'));
        if ($range['days'] > ListeningStats::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'The range can be at most '.ListeningStats::MAX_DAYS.' days.']);
        }

        $client = $request->filled('client') ? Client::byApiToken((string) $request->query('client')) : null;
        $hidden = LibrarySources::hiddenForRequest($client, $request->query('scope'));

        return response()->json(ListeningStats::compute($range['from'], $range['to'], $hidden, $request->integer('limit', 10)));
    }
}
