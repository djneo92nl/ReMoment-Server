<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DeviceListResource;
use App\Models\Client;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hardware_id' => ['nullable', 'string', 'max:100'],
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'build_number' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ]);

        $ip = $request->ip();

        if (!empty($data['hardware_id'])) {
            $existing = Client::where('hardware_id', $data['hardware_id'])->first();
            if ($existing) {
                $existing->update(array_merge($data, ['ip_address' => $ip]));

                return response()->json([
                    'registration_token' => $existing->registration_token,
                    'status' => $existing->status,
                ]);
            }
        }

        $client = Client::create(array_merge($data, [
            'ip_address' => $ip,
            'status' => 'pending',
            'registration_token' => Client::generateToken(),
        ]));

        return response()->json([
            'registration_token' => $client->registration_token,
            'status' => $client->status,
        ], 201);
    }

    public function status(string $registrationToken): JsonResponse
    {
        $client = Client::where('registration_token', $registrationToken)->firstOrFail();

        if ($client->status === 'pending') {
            return response()->json(['status' => 'pending']);
        }

        $devices = $this->resolveDevices($client);

        return response()->json([
            'status' => 'approved',
            'type' => $client->type,
            'api_token' => $client->api_token,
            'devices' => DeviceListResource::collection($devices),
        ]);
    }

    public function devices(string $apiToken): JsonResponse
    {
        $client = Client::where('api_token', $apiToken)->firstOrFail();
        $client->update(['last_seen_at' => now()]);

        return response()->json([
            'devices' => DeviceListResource::collection($this->resolveDevices($client)),
        ]);
    }

    public function heartbeat(Request $request, string $apiToken): JsonResponse
    {
        $client = Client::where('api_token', $apiToken)->firstOrFail();

        $data = $request->validate([
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'build_number' => ['nullable', 'integer', 'min:0'],
        ]);

        $client->update(array_merge($data, [
            'ip_address' => $request->ip(),
            'last_seen_at' => now(),
        ]));

        return response()->json(['status' => 'ok']);
    }

    private function resolveDevices(Client $client)
    {
        $assigned = $client->devices;

        if ($client->type === 'multi' && $assigned->isEmpty()) {
            return Device::orderBy('device_name')->get();
        }

        return $assigned;
    }
}
