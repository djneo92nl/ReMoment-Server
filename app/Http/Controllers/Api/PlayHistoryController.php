<?php

namespace App\Http\Controllers\Api;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibrarySources;
use App\Domain\Stats\PlayHistory;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What was played, newest first: household-wide, per device, per album, per artist (docs/api/history.md). */
class PlayHistoryController extends Controller
{
    /** GET /api/library/history */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['device_id' => ['nullable', 'integer', 'exists:devices,id']]);
        $plays = $this->query($request, true);

        if ($request->filled('device_id')) {
            $plays->where('plays.device_id', $request->integer('device_id'));
        }

        return $this->respond($request, $plays);
    }

    /** GET /api/devices/{device}/history */
    public function device(Request $request, Device $device): JsonResponse
    {
        return $this->respond($request, $this->query($request, true)->where('plays.device_id', $device->id));
    }

    /** GET /api/library/albums/{album}/plays */
    public function album(Request $request, Album $album): JsonResponse
    {
        $request->validate(['device_id' => ['nullable', 'integer', 'exists:devices,id']]);
        $plays = $this->query($request, false)->whereIn('plays.track_id', $album->tracks()->select('tracks.id'));

        return $this->respond($request, $request->filled('device_id') ? $plays->where('plays.device_id', $request->integer('device_id')) : $plays);
    }

    /** GET /api/library/artists/{artist}/plays */
    public function artist(Request $request, Artist $artist): JsonResponse
    {
        $request->validate(['device_id' => ['nullable', 'integer', 'exists:devices,id']]);
        $plays = $this->query($request, false)->whereIn('plays.track_id', $artist->tracks()->select('tracks.id'));

        return $this->respond($request, $request->filled('device_id') ? $plays->where('plays.device_id', $request->integer('device_id')) : $plays);
    }

    /** @param bool $scoped apply `scope` / `client` (the household list); the album and artist lists show every play of the record */
    private function query(Request $request, bool $scoped)
    {
        $request->validate([
            'cursor' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
            'client' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:'.implode(',', LeadingSource::apiScopes())],
        ]);

        $hidden = null;
        if ($scoped) {
            $client = $request->filled('client') ? Client::byApiToken((string) $request->query('client')) : null;
            $hidden = LibrarySources::hiddenForRequest($client, $request->query('scope'));
        }

        return PlayHistory::filtered($request->query('from'), $request->query('to'), $request->query('q'), $hidden);
    }

    private function respond(Request $request, $plays): JsonResponse
    {
        return response()->json(PlayHistory::page($plays, $request->query('cursor'), $request->integer('limit', PlayHistory::PAGE)));
    }
}
