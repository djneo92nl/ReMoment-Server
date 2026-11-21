<?php

namespace App\Http\Controllers\Api;

use App\Domain\Radio\RadioBrowser;
use App\Domain\Radio\RadioStations;
use App\Http\Controllers\Controller;
use App\Models\RadioStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Managing radio stations for apps (docs/api/admin-radio.md), admin token only: add, change, remove, and the stream finder
 * (radio-browser.info). Playing, the favourite and the per-device list stay open (`/devices/{id}/radio`, `PUT /radio/{id}/favorite`).
 */
class AdminRadioStationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $stations = RadioStation::with('meta')
            ->when($data['q'] ?? null, fn ($query, $q) => $query->where('name', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $stations->map(fn (RadioStation $s) => RadioStations::toAdminArray($s))->values()]);
    }

    public function show(RadioStation $station): JsonResponse
    {
        return response()->json(['data' => RadioStations::toAdminArray($station)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(required: true));

        return response()->json(['data' => RadioStations::toAdminArray(RadioStations::save(null, $data, $data['identifiers'] ?? []))], 201);
    }

    public function update(Request $request, RadioStation $station): JsonResponse
    {
        $data = $request->validate($this->rules(required: false));

        return response()->json(['data' => RadioStations::toAdminArray(RadioStations::save($station, $data, $data['identifiers'] ?? []))]);
    }

    public function destroy(RadioStation $station): JsonResponse
    {
        RadioStations::delete($station);

        return response()->json(['status' => 'ok']);
    }

    /** The stream finder: stations of radio-browser.info by name (an empty list for under 2 characters or when the directory is unreachable). */
    public function search(Request $request, RadioBrowser $browser): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:100']]);

        return response()->json(['data' => $browser->search($data['q'])]);
    }

    /** Adds a search result as a station: 201, or 200 with the station that already has this stream URL. */
    public function addFromDirectory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'genre' => ['nullable', 'string', 'max:100'],
            'uuid' => ['nullable', 'string', 'max:64'],
        ]);

        $station = RadioStations::addFromDirectory($data);

        return response()->json(['data' => RadioStations::toAdminArray($station)], $station->wasRecentlyCreated ? 201 : 200);
    }

    private function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'genre' => ['sometimes', 'nullable', 'string', 'max:100'],
            // platform => identifier; an empty value removes that identifier
            'identifiers' => ['sometimes', 'array', fn ($attribute, $value, $fail) => array_filter(array_keys($value), fn ($key) => !preg_match('/^[a-z0-9_]{1,40}$/', (string) $key)) === [] ?: $fail('Identifier names are lowercase letters, digits and underscores.')],
            'identifiers.*' => ['nullable', 'string', 'max:2048'],
            'identifiers.url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
