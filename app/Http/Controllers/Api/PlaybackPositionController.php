<?php

namespace App\Http\Controllers\Api;

use App\Domain\Resume\PlaybackPositions;
use App\Domain\Resume\PositionItems;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Resume positions of long tracks and podcast episodes (docs/api/playback-positions.md). Open, like playback itself. */
class PlaybackPositionController extends Controller
{
    /** The bookmarks, most recently kept first: what "continue listening" shows. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(['data' => PositionItems::recent($request->integer('limit', 30))]);
    }

    /** Sets a position by hand, for an app that follows playback itself; a position at the end clears it. */
    public function update(Request $request, string $kind, int $id): JsonResponse
    {
        $data = $request->validate([
            'position' => ['required', 'integer', 'min:0'],
            'duration' => ['nullable', 'integer', 'min:1'],
        ]);

        if (!PositionItems::exists($kind, $id)) {
            return response()->json(['error' => 'not_found', 'message' => 'No such '.$kind.'.'], 404);
        }

        $position = PlaybackPositions::set($kind, $id, $data['position'], $data['duration'] ?? PositionItems::duration($kind, $id));

        return response()->json(['status' => 'ok', 'kind' => $kind, 'id' => $id, 'position' => $position?->position ?? 0, 'cleared' => $position === null]);
    }

    public function destroy(string $kind, int $id): JsonResponse
    {
        PlaybackPositions::clear($kind, $id);

        return response()->json(['status' => 'ok']);
    }
}
