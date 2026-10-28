<?php

namespace App\Http\Controllers\Api;

use App\Domain\Availability\BrowsesExternalCatalogue;
use App\Domain\Availability\ExternalCatalogues;
use App\Domain\Availability\ExternalItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Browse, and add to the library, what an external service offers (docs/api/availability.md, "External catalogues").
 * Not for client devices, like the rest of the catalogue; playing is `POST /devices/{id}/library/play-external`.
 */
class ExternalCatalogueController extends Controller
{
    public function search(Request $request, string $provider): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'type' => ['required', 'in:artist,album,track'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        return $this->guarded($request, $provider, fn (BrowsesExternalCatalogue $catalogue) => response()->json([
            'data' => array_map(fn (ExternalItem $i) => $i->toArray(), $catalogue->search($data['q'], $data['type'], (int) ($data['limit'] ?? 10))),
        ]));
    }

    public function artist(Request $request, string $provider, string $id): JsonResponse
    {
        return $this->guarded($request, $provider, function (BrowsesExternalCatalogue $catalogue) use ($id) {
            $found = $catalogue->artist($id);

            return $found === null
                ? response()->json(['error' => 'not_found', 'message' => 'The service does not know this artist.'], 404)
                : response()->json(['artist' => $found['artist']->toArray(), 'albums' => array_map(fn (ExternalItem $i) => $i->toArray(), $found['albums'])]);
        });
    }

    public function album(Request $request, string $provider, string $id): JsonResponse
    {
        return $this->guarded($request, $provider, function (BrowsesExternalCatalogue $catalogue) use ($id) {
            $found = $catalogue->album($id);

            return $found === null
                ? response()->json(['error' => 'not_found', 'message' => 'The service does not know this album.'], 404)
                : response()->json(['album' => $found['album']->toArray(), 'tracks' => array_map(fn (ExternalItem $i) => $i->toArray(), $found['tracks'])]);
        });
    }

    /** The account's saved albums, liked songs or recently played tracks (`/saved-albums`, `/liked-tracks`, `/recent`). */
    public function account(Request $request, string $provider, string $section): JsonResponse
    {
        abort_unless(in_array($section, ['saved-albums', 'liked-tracks', 'recent'], true), 404);

        return $this->guarded($request, $provider, fn (BrowsesExternalCatalogue $catalogue) => response()->json([
            'data' => array_map(fn (ExternalItem $i) => $i->toArray(), $catalogue->account($section)),
        ]));
    }

    public function playlists(Request $request, string $provider): JsonResponse
    {
        return $this->guarded($request, $provider, fn (BrowsesExternalCatalogue $catalogue) => response()->json([
            'data' => array_map(fn (ExternalItem $i) => $i->toArray(), $catalogue->playlists()),
        ]));
    }

    public function playlist(Request $request, string $provider, string $id): JsonResponse
    {
        return $this->guarded($request, $provider, function (BrowsesExternalCatalogue $catalogue) use ($id) {
            $found = $catalogue->playlist($id);

            return $found === null
                ? response()->json(['error' => 'not_found', 'message' => 'The service does not know this playlist.'], 404)
                : response()->json([
                    'playlist' => $found['playlist']->toArray(),
                    'tracks' => $found['tracks'] === null ? null : array_map(fn (ExternalItem $i) => $i->toArray(), $found['tracks']),
                ]);
        });
    }

    /** Runs a call on the provider's catalogue: 403 for a client device, 404 unknown provider, 422 not connected, 502 when it fails. */
    private function guarded(Request $request, string $provider, \Closure $call): JsonResponse|Response
    {
        if ($request->filled('client')) {
            return response()->json(['error' => 'forbidden', 'message' => 'External catalogues are not available to client devices.'], 403);
        }

        $catalogue = ExternalCatalogues::for($provider);
        if ($catalogue === null) {
            return response()->json(['error' => 'unknown_provider', 'message' => "No catalogue called \"{$provider}\"."], 404);
        }
        if (!$catalogue->enabled()) {
            return response()->json(['error' => 'not_connected', 'message' => ucfirst($provider).' is not connected.'], 422);
        }

        try {
            return $call($catalogue);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'driver_error', 'message' => ucfirst($provider).' did not answer: '.$e->getMessage()], 502);
        }
    }
}
