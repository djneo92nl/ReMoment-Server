<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Domain\Device\State;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

/** The 503 / 422 answers every capability endpoint shares. */
trait GuardsDeviceAccess
{
    private function assertReachable(Device $device): ?JsonResponse
    {
        if ($device->state === State::Unreachable) {
            return response()->json([
                'error' => 'unreachable',
                'message' => 'Device is not reachable.',
            ], 503);
        }

        return null;
    }

    private function unsupported(string $capability): JsonResponse
    {
        return response()->json([
            'error' => 'unsupported',
            'message' => "This device does not support {$capability}.",
        ], 422);
    }
}
