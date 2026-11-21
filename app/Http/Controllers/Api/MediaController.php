<?php

namespace App\Http\Controllers\Api;

use App\Domain\MediaServers\DeviceMedia;
use App\Domain\MediaServers\MediaServers;
use App\Http\Controllers\Api\Concerns\GuardsDeviceAccess;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\MediaServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Media servers and what they offer a device: the servers, the shelves of a device and starting an item there (docs/api/media-servers.md). */
class MediaController extends Controller
{
    use GuardsDeviceAccess;

    public function servers(): JsonResponse
    {
        return response()->json(['data' => MediaServers::all()->map(fn (MediaServer $server) => $this->server($server))->values()]);
    }

    public function show(Device $device): JsonResponse
    {
        if (!DeviceMedia::available($device)) {
            return $this->notAvailable();
        }

        $shelves = DeviceMedia::shelves($device);

        if ($shelves === null) {
            return response()->json(['error' => 'driver_error', 'message' => 'No media server answered: check the server address and key.'], 502);
        }

        return response()->json(['active' => DeviceMedia::idle($device)] + $shelves);
    }

    public function play(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'item' => ['required', 'string', 'max:128'],
            'server' => ['nullable', 'integer', 'min:1'],
            'position' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (!DeviceMedia::available($device)) {
            return $this->notAvailable();
        }

        return $this->answerDriverCall(function () use ($device, $data) {
            DeviceMedia::play($device, $data['item'], isset($data['server']) ? (int) $data['server'] : null, (float) ($data['position'] ?? 0));

            return ['status' => 'ok', 'item' => $data['item']];
        });
    }

    /** @return array<string, mixed> */
    private function server(MediaServer $server): array
    {
        $entry = MediaServers::types()[$server->driver] ?? null;
        $driver = MediaServers::driver($server);

        return [
            'id' => $server->id,
            'name' => $server->name,
            'driver' => $server->driver,
            'label' => $entry['label'] ?? $server->driver,
            'base_url' => $server->baseUrl(),
            'enabled' => $server->enabled,
            'configured' => $driver?->configured() ?? false,
            'capabilities' => MediaServers::capabilities($server),
        ];
    }

    private function notAvailable(): JsonResponse
    {
        return response()->json(['error' => 'unsupported', 'message' => 'This device has no media server: it needs a server with complete credentials that can play on it (Settings › Services).'], 422);
    }
}
