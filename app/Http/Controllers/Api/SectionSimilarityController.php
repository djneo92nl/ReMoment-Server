<?php

namespace App\Http\Controllers\Api;

use App\Domain\Analysis\DeepAnalysis;
use App\Domain\Library\ApiItems;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\SimilarSections;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Media\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sections of other tracks that sound like one section of this track (workstream 16.5); docs/api/section-similarity.md. */
class SectionSimilarityController extends Controller
{
    /** `GET /api/library/tracks/{id}/similar-sections?section=&limit=&scope=&client=` */
    public function show(Request $request, Track $track): JsonResponse
    {
        $data = $request->validate([
            'section' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'between:1,'.max(1, (int) config('analysis.section_matches_max'))],
            'client' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:'.implode(',', LeadingSource::apiScopes())],
        ]);

        $client = $request->filled('client') ? Client::byApiToken((string) $request->input('client')) : null;
        $similar = new SimilarSections($track, LibrarySources::hiddenForRequest($client, $request->input('scope')));
        $sections = $similar->sections();

        if ($sections === []) {
            $state = ($file = DeepAnalysis::fileFor($track)) ? DeepAnalysis::state($file, $file->analysis()->first(), 'section_sound')['state'] : 'none';

            return response()->json([
                'error' => 'not_analysed',
                'message' => 'The sound of this track\'s sections has not been analysed yet (deep analysis kind `section_sound`, after `sections`).',
                'state' => $state,
            ], 422);
        }

        $index = $data['section'] ?? $sections[0]['index'];
        $matches = $similar->matches($index, $data['limit'] ?? 10);
        if ($matches === null) {
            return response()->json(['error' => 'unknown_section', 'message' => "This track has no section {$index}."], 404);
        }

        $tracks = Track::query()->with(['artist', 'album'])->findMany(array_column($matches, 'track_id'))->keyBy('id');

        return response()->json([
            'canonical_id' => $track->id,
            'section' => collect($sections)->firstWhere('index', $index),
            'sections' => $sections,
            'data' => collect($matches)->filter(fn ($m) => isset($tracks[$m['track_id']]))
                ->map(fn ($m) => ['track' => ApiItems::track($tracks[$m['track_id']]), 'section' => $m['section'], 'similarity' => $m['similarity']])->values(),
        ]);
    }
}
