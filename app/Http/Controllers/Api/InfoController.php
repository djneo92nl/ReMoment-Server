<?php

namespace App\Http\Controllers\Api;

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

        return response()->json([
            'name' => config('app.name'),
            'version' => (string) config('app.version'),
            'api_version' => self::API_VERSION,
            'base_url' => $baseUrl,
            'api_base_url' => "{$baseUrl}/api",
            'artwork_base_url' => $baseUrl,
            'mqtt' => [
                'host' => config('mqtt.public_host') ?: $request->getHost(),
                'port' => (int) config('mqtt.public_port'),
                'topic_prefix' => 'remoment/player',
            ],
        ]);
    }
}
