<?php

namespace App\Http\Controllers\Api;

use App\Domain\MediaServers\DeviceMedia;
use App\Http\Controllers\Api\Concerns\GuardsDeviceAccess;
use App\Http\Controllers\Controller;
use App\Integrations\Jellyfin\JellyfinItems;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jellyfin on a TV (docs/api/jellyfin.md): kept for the clients that already use it, a thin wrapper over the generic
 * `/api/devices/{id}/media` routes (MediaController) limited to Jellyfin servers, in the old shape (`ticks`, `item_id`).
 */
class JellyfinController extends Controller
{
    use GuardsDeviceAccess;

    public function index(Device $device): JsonResponse
    {
        if (!DeviceMedia::available($device, 'jellyfin')) {
            return $this->notAvailable();
        }

        $shelves = DeviceMedia::shelves($device, 'jellyfin');

        if ($shelves === null) {
            return response()->json(['error' => 'driver_error', 'message' => 'Jellyfin did not answer: check the server address and key.'], 502);
        }

        return response()->json([
            'active' => DeviceMedia::idle($device, 'jellyfin'),
            'continue_watching' => array_map($this->tile(...), $shelves['continue_watching']),
            'next_up' => array_map($this->tile(...), $shelves['next_up']),
        ]);
    }

    public function play(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate(['item_id' => ['required', 'string', 'max:64'], 'position_ticks' => ['nullable', 'integer', 'min:0']]);

        if (!DeviceMedia::available($device, 'jellyfin')) {
            return $this->notAvailable();
        }

        return $this->answerDriverCall(function () use ($device, $data) {
            DeviceMedia::play($device, $data['item_id'], null, ($data['position_ticks'] ?? 0) / JellyfinItems::TICKS_PER_SECOND, 'jellyfin');

            return ['status' => 'ok', 'item_id' => $data['item_id']];
        });
    }

    /**
     * @param  array<string, mixed>  $item  a normalised item
     * @return array{id: string, title: string, subtitle: ?string, image: ?string, progress: int, ticks: int}
     */
    private function tile(array $item): array
    {
        return [
            'id' => $item['item_id'],
            'title' => $item['title'],
            'subtitle' => $item['subtitle'],
            'image' => $item['image'],
            'progress' => $item['progress'],
            'ticks' => JellyfinItems::ticks($item['resume_position']),
        ];
    }

    private function notAvailable(): JsonResponse
    {
        return response()->json(['error' => 'unsupported', 'message' => 'This device has no Jellyfin: it needs a paired Philips TV and a Jellyfin key (Settings › Services).'], 422);
    }
}
