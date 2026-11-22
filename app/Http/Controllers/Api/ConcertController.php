<?php

namespace App\Http\Controllers\Api;

use App\Domain\Concerts\Concerts;
use App\Http\Controllers\Controller;
use App\Models\Media\Artist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Upcoming concerts of library artists from MusicBrainz events (docs/api/concerts.md). */
class ConcertController extends Controller
{
    /** `GET /api/library/concerts?artist_id=&location=&all=&limit=` */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'artist_id' => ['nullable', 'integer', 'exists:artists,id'],
            'location' => ['nullable', 'string', 'max:200'],
            'all' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ]);

        return response()->json($this->answer($request, $data['artist_id'] ?? null, (int) ($data['limit'] ?? 50)));
    }

    /** `GET /api/library/artists/{artist}/concerts` (the same parameters, minus `artist_id`) */
    public function artist(Request $request, Artist $artist): JsonResponse
    {
        $request->validate(['location' => ['nullable', 'string', 'max:200'], 'all' => ['nullable', 'boolean'], 'limit' => ['nullable', 'integer', 'between:1,200']]);

        return response()->json($this->answer($request, $artist->id, (int) $request->query('limit', 50)));
    }

    /** `all=1` shows every place; `location=` overrides the setting for this call. */
    private function answer(Request $request, ?int $artistId, int $limit): array
    {
        $location = $request->boolean('all') ? '' : ($request->filled('location') ? (string) $request->query('location') : null);

        return Concerts::upcoming($artistId, $location, $limit);
    }
}
