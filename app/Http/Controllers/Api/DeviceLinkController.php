<?php

namespace App\Http\Controllers\Api;

use App\Domain\SubDevices\DeviceLinks;
use App\Domain\SubDevices\InvalidDeviceLink;
use App\Domain\SubDevices\LinkMeta;
use App\Domain\SubDevices\LinkSuggestions;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceLink;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin routes for sub device links (docs/api/sub-devices.md): list, add, change and remove a link, and the links a host
 * reports itself. The relation is read by everyone in `GET /api/devices/{id}` (`host`, `sub_devices`).
 */
class DeviceLinkController extends Controller
{
    /** The links where the device is the host (`sub_devices`) and where it is the sub device (`hosts`). */
    public function index(Device $device): JsonResponse
    {
        return response()->json([
            'sub_devices' => DeviceLinks::subLinks($device)->map(fn (DeviceLink $link) => DeviceLinks::toArray($link->setRelation('host', $device)))->all(),
            'hosts' => DeviceLinks::hostLinks($device)->map(fn (DeviceLink $link) => DeviceLinks::toArray($link->setRelation('device', $device)))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'integer', 'exists:devices,id'],
            'host_device_id' => ['required', 'integer', 'exists:devices,id'],
            'kind' => ['required', 'string', Rule::in(DeviceLink::KINDS)],
            'via' => ['sometimes', 'nullable', 'string', 'max:191'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ]);

        try {
            $link = DeviceLink::create([
                'device_id' => $data['device_id'],
                'host_device_id' => $data['host_device_id'],
                'kind' => $data['kind'],
                'via' => $data['via'] ?? '',
                'meta' => LinkMeta::replace($data['meta'] ?? null, null),
            ]);
        } catch (InvalidDeviceLink $e) {
            return $this->invalid($e->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->invalid('These devices are already linked that way.');
        }

        return response()->json(DeviceLinks::toArray($link), 201);
    }

    public function update(Request $request, DeviceLink $link): JsonResponse
    {
        $data = $request->validate([
            'via' => ['sometimes', 'nullable', 'string', 'max:191'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ]);

        try {
            if (array_key_exists('via', $data)) {
                $link->via = (string) $data['via'];
            }
            if (array_key_exists('meta', $data)) {
                // What the server keeps on the link (`state`) survives an edit of the settings.
                $link->meta = LinkMeta::replace($data['meta'], $link->meta);
            }

            $link->save();
        } catch (InvalidDeviceLink $e) {
            return $this->invalid($e->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->invalid('These devices are already linked that way.');
        }

        return response()->json(DeviceLinks::toArray($link));
    }

    public function destroy(DeviceLink $link): JsonResponse
    {
        $link->delete();

        return response()->json(['status' => 'ok']);
    }

    /** What the host's own Products list says: devices of ours it knows, to link with one request. */
    public function suggestions(Device $device): JsonResponse
    {
        if (!method_exists($device->driver, 'getProducts')) {
            return response()->json(['suggestions' => []]);
        }

        try {
            $products = $device->driver->getProducts();
        } catch (\Throwable $e) {
            return response()->json(['error' => 'driver_error', 'message' => 'The device did not respond: '.$e->getMessage()], 502);
        }

        return response()->json(['suggestions' => LinkSuggestions::fromProducts($device, $products)]);
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['error' => 'invalid', 'message' => $message], 422);
    }
}
