<?php

namespace App\Http\Controllers\Api;

use App\Domain\Device\RemoteKey;
use App\Http\Controllers\Api\Concerns\GuardsDeviceAccess;
use App\Http\Controllers\Controller;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\RemoteControlInterface;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The keys of a TV's remote (capability `remote_control`): which a device accepts, and pressing one. */
class RemoteKeysController extends Controller
{
    use GuardsDeviceAccess;

    public function index(Device $device): JsonResponse
    {
        return $this->withDriver($device, RemoteControlInterface::class, 'remote_control', fn (RemoteControlInterface $driver) => [
            'supported_keys' => array_map(fn (RemoteKey $key) => $key->value, $driver->supportedKeys()),
        ], reachable: false);
    }

    public function press(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string']]);

        return $this->withDriver($device, RemoteControlInterface::class, 'remote_control', function (RemoteControlInterface $driver) use ($data) {
            $key = RemoteKey::tryFrom($data['key']) ?? throw new \InvalidArgumentException("Unknown key {$data['key']}.");

            if (!in_array($key, $driver->supportedKeys(), true)) {
                throw new UnsupportedOperationException("This device has no {$key->value} key.");
            }

            $driver->sendKey($key);

            return ['status' => 'ok', 'key' => $key->value];
        });
    }
}
