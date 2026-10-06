<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\NowPlayingArtwork;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bootstrap info for client devices that just found the server (e.g. via mDNS):
 * where the API, the MQTT broker and artwork live, as seen from the client.
 */
class InfoController extends Controller
{
    /** Bumped on breaking changes to the client-facing API/MQTT contract. */
    public const API_VERSION = 1;

    public function __invoke(Request $request): JsonResponse
    {
        $baseUrl = $request->getSchemeAndHttpHost();
        $mqttHost = config('mqtt.public_host') ?: $request->getHost();
        $wsPort = (int) config('mqtt.public_ws_port');

        return response()->json([
            'name' => config('app.name'),
            'version' => (string) config('app.version'),
            'api_version' => self::API_VERSION,
            'base_url' => $baseUrl,
            'api_base_url' => "{$baseUrl}/api",
            'artwork_base_url' => $baseUrl,
            'mqtt' => [
                'host' => $mqttHost,
                'port' => (int) config('mqtt.public_port'),
                'topic_prefix' => 'remoment/player',
                // MQTT over WebSocket, for browsers and apps that can't open a raw TCP socket.
                'ws_port' => $wsPort,
                'ws_url' => config('mqtt.ws_url') ?: ($request->isSecure() ? 'wss' : 'ws')."://{$mqttHost}:{$wsPort}",
            ],
            // Shown by clients whenever there is no artwork; rendered on the spot if missing.
            'placeholder' => NowPlayingArtwork::placeholder(),
        ]);
    }
}
