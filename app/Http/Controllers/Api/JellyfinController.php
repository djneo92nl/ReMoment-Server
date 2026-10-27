<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\GuardsDeviceAccess;
use App\Http\Controllers\Controller;
use App\Integrations\Jellyfin\JellyfinTv;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Jellyfin on a TV: what to continue or watch next, and starting it in the Jellyfin app there (docs/api/jellyfin.md). */
class JellyfinController extends Controller
{
    use GuardsDeviceAccess;

    public function index(Device $device): JsonResponse
    {
        if (!JellyfinTv::available($device)) {
            return $this->notAvailable();
        }

        $lists = JellyfinTv::lists($device);

        if ($lists === null) {
            return response()->json(['error' => 'driver_error', 'message' => 'Jellyfin did not answer: check the server address and key.'], 502);
        }

        return response()->json(['active' => JellyfinTv::idle($device)] + $lists);
    }

    public function play(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate(['item_id' => ['required', 'string', 'max:64'], 'position_ticks' => ['nullable', 'integer', 'min:0']]);

        if (!JellyfinTv::available($device)) {
            return $this->notAvailable();
        }

        return $this->answerDriverCall(function () use ($device, $data) {
            JellyfinTv::play($device, $data['item_id'], (int) ($data['position_ticks'] ?? 0));

            return ['status' => 'ok', 'item_id' => $data['item_id']];
        });
    }

    private function notAvailable(): JsonResponse
    {
        return response()->json(['error' => 'unsupported', 'message' => 'This device has no Jellyfin: it needs a paired Philips TV and a Jellyfin key (Settings › Services).'], 422);
    }
}
