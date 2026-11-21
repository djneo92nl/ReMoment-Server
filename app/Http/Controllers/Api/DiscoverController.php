<?php

namespace App\Http\Controllers\Api;

use App\Domain\Library\Discover;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibrarySources;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** GET /api/library/discover: the Discover shelves as data, sections with items and a reason each (docs/api/discover.md). */
class DiscoverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sections' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('discover.max_items')],
            'seed' => ['nullable', 'integer'],
            'client' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', Rule::in(LeadingSource::apiScopes())],
        ]);

        $only = null;
        if (!empty($data['sections'])) {
            $only = array_values(array_filter(array_map('trim', explode(',', $data['sections']))));
            $unknown = array_diff($only, Discover::keys());
            if ($unknown !== []) {
                return response()->json(['message' => 'Unknown section: '.implode(', ', $unknown).'.', 'errors' => ['sections' => ['Known sections: '.implode(', ', Discover::keys()).'.']]], 422);
            }
        }

        $client = $request->filled('client') ? Client::byApiToken((string) $request->query('client')) : null;
        $hidden = LibrarySources::hiddenForRequest($client, $request->query('scope'));

        return response()->json(['data' => Discover::sections(
            $hidden,
            $only,
            isset($data['limit']) ? (int) $data['limit'] : null,
            // The catalogue (release-groups nobody owns) is not for a registered client device.
            catalogue: !$request->filled('client'),
            seed: isset($data['seed']) ? (int) $data['seed'] : null,
        )]);
    }
}
