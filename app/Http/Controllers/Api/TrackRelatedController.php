<?php

namespace App\Http\Controllers\Api;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\RelatedWorks;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Media\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Related works of one track from MusicBrainz relationships (docs/api/related-works.md). */
class TrackRelatedController extends Controller
{
    /** `GET /api/library/tracks/{track}/related?scope=&client=` */
    public function show(Request $request, Track $track): JsonResponse
    {
        $request->validate([
            'client' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:'.implode(',', LeadingSource::apiScopes())],
        ]);

        $client = $request->filled('client') ? Client::byApiToken((string) $request->query('client')) : null;
        $found = RelatedWorks::for($track, LibrarySources::hiddenForRequest($client, $request->query('scope')));

        return response()->json(['canonical_id' => $track->id, 'data' => $found['related'], 'versions' => $found['versions']]);
    }
}
