<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\NowPlayingArtwork;
use App\Domain\Spotify\LibrespotEvents;
use App\Domain\Spotify\LibrespotStream;
use App\Domain\Storage\StreamUrl;
use App\Http\Controllers\Api\Concerns\GuardsDeviceAccess;
use App\Http\Controllers\Controller;
use App\Integrations\Contracts\PlayStreamUrlInterface;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Spotify played through the server: a librespot container turns Spotify Connect into an Icecast stream and a
 * speaker without Spotify plays that URL. See docs/architecture/spotify-stream.md.
 */
class SpotifyStreamController extends Controller
{
    use GuardsDeviceAccess;

    /** The stream's address and whether it is switched on, for clients that want to play it themselves. */
    public function show(): JsonResponse
    {
        return response()->json([
            'enabled' => LibrespotStream::enabled(),
            'name' => config('librespot.name'),
            'url' => LibrespotStream::enabled() ? LibrespotStream::url() : null,
        ]);
    }

    /** Play the stream on a device (capability `stream_url`); the user then picks the receiver's name in Spotify. */
    public function play(Device $device): JsonResponse
    {
        if (!LibrespotStream::enabled()) {
            return response()->json([
                'error' => 'unsupported',
                'message' => 'The Spotify stream is not enabled (LIBRESPOT_ENABLED).',
            ], 422);
        }

        return $this->withDriver($device, PlayStreamUrlInterface::class, 'stream_url', function (PlayStreamUrlInterface $driver) use ($device) {
            $url = LibrespotStream::url();
            $logo = ($proxy = NowPlayingArtwork::logo('spotify')['proxy_320'] ?? null) ? StreamUrl::storage($proxy) : null;

            $driver->playStreamUrl($url, LibrespotStream::title(), $logo);
            LibrespotEvents::setTarget($device->id);

            return ['status' => 'ok', 'url' => $url, 'receiver' => config('librespot.name')];
        });
    }

    /** The container's `--onevent` hook; open to the LAN like the rest of the API, but with a shared secret. */
    public function event(Request $request, LibrespotEvents $events): JsonResponse
    {
        if (!LibrespotStream::enabled()) {
            return response()->json(['error' => 'unsupported'], 422);
        }

        if (!hash_equals((string) config('librespot.event_secret'), (string) $request->header('X-Librespot-Secret'))) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $events->handle($request->only(['event', 'track_id', 'position_ms', 'duration_ms', 'name', 'artists', 'album', 'covers']));

        return response()->json(['status' => 'ok']);
    }
}
