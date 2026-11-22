<?php

namespace App\Http\Controllers\Api;

use App\Domain\Library\ArtistTour;
use App\Domain\Library\IdAliases;
use App\Domain\Library\Journey;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibrarySources;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two guided queues of the rediscovery workstream, as lists (docs/api/journey.md, docs/api/artist-tour.md): a journey from one
 * track to another through the sound space, and a tour through one artist's tracks. Playing them is
 * `POST /api/devices/{id}/library/play-journey` and `.../play-artist-tour`.
 */
class LibraryTourController extends Controller
{
    /** `GET /api/library/journey?from=&to=&length=&device_id=&scope=&client=` */
    public function journey(Request $request): JsonResponse
    {
        app(IdAliases::class)->resolveInput($request, ['from' => 'track', 'to' => 'track']);
        $data = $request->validate([
            'from' => ['required', 'integer', 'exists:tracks,id'],
            'to' => ['required', 'integer', 'exists:tracks,id', 'different:from'],
            'length' => ['nullable', 'integer', 'between:'.Journey::MIN_LENGTH.','.Journey::MAX_LENGTH],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'client' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:'.implode(',', LeadingSource::apiScopes())],
        ]);

        $client = $request->filled('client') ? Client::byApiToken((string) $request->input('client')) : null;
        $journey = new Journey(
            Track::findOrFail($data['from']), Track::findOrFail($data['to']),
            LibrarySources::hiddenForRequest($client, $request->input('scope')),
            isset($data['device_id']) ? Device::find($data['device_id']) : null,
        );

        if ($end = $journey->missing()) {
            return response()->json(['error' => 'not_analysed', 'message' => "The {$end} track has no sound embedding yet (the audio analysis with the ML models has not run for it).", 'track' => $end], 422);
        }

        $steps = $journey->steps(isset($data['length']) ? (int) $data['length'] : null);
        if ($steps === []) {
            return response()->json(['error' => 'not_enough_tracks', 'message' => 'The library has too few analysed tracks to build this journey.'], 422);
        }

        return response()->json(['data' => $journey->items($steps)]);
    }

    /** `GET /api/library/artists/{artist}/tour?length=&device_id=&scope=&client=` */
    public function artistTour(Request $request, Artist $artist): JsonResponse
    {
        $data = $request->validate([
            'length' => ['nullable', 'integer', 'between:4,60'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'client' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:'.implode(',', LeadingSource::apiScopes())],
        ]);

        $client = $request->filled('client') ? Client::byApiToken((string) $request->input('client')) : null;
        $picks = ArtistTour::picks($artist, LibrarySources::hiddenForRequest($client, $request->input('scope')), isset($data['device_id']) ? Device::find($data['device_id']) : null, isset($data['length']) ? (int) $data['length'] : null);
        if ($picks === []) {
            return response()->json(['error' => 'not_enough_tracks', 'message' => 'This artist has too few tracks for a tour.'], 422);
        }

        return response()->json(['canonical_id' => $artist->id, 'data' => ArtistTour::items($picks)]);
    }
}
