<?php

namespace App\Http\Controllers\Api;

use App\Domain\Device\DeviceAdmin;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DeviceListResource;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Adding, changing, hiding and removing devices for apps (docs/api/admin-devices.md), admin token only: the actions of
 * `/devices/create`, `/devices/{id}/edit` and `/settings/devices`, through Domain\Device\DeviceAdmin. Finding devices on the
 * network is `AdminDiscoveryController`. Reading devices stays open (`GET /api/devices`).
 */
class AdminDeviceController extends Controller
{
    /** The models a device can be added as (brand, product, driver name, virtual). */
    public function models(): JsonResponse
    {
        return response()->json(['data' => DeviceAdmin::models()]);
    }

    /** Every device, hidden ones too (`GET /api/devices` is the list for pickers). */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Device::orderBy('device_name')->get()->map(fn (Device $d) => $this->payload($d))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_name' => ['required', 'string', 'max:255'],
            'device_brand_name' => ['required', 'string', 'max:255'],
            'device_product_type' => ['required', 'string', 'max:255'],
            'ip_address' => DeviceAdmin::isVirtual($request->input('device_brand_name'), $request->input('device_product_type'))
                ? ['nullable', 'string', 'max:255'] : ['required', 'string', 'max:255'],
            'hidden' => ['sometimes', 'boolean'],
        ]);

        $model = DeviceAdmin::attributesFor($data['device_brand_name'], $data['device_product_type']);
        if ($model === null) {
            return $this->unknownProduct();
        }

        $device = DeviceAdmin::create($model + ['device_name' => $data['device_name'], 'ip_address' => $data['ip_address'] ?? null, 'hidden' => $data['hidden'] ?? false]);

        return response()->json(['data' => $this->payload($device)], 201);
    }

    public function show(Device $device): JsonResponse
    {
        return response()->json(['data' => $this->payload($device)]);
    }

    /** Name, address, hidden, and (as a pair) the model, which picks the driver again. */
    public function update(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'device_name' => ['sometimes', 'string', 'max:255'],
            'ip_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hidden' => ['sometimes', 'boolean'],
            'device_brand_name' => ['required_with:device_product_type', 'string', 'max:255'],
            'device_product_type' => ['required_with:device_brand_name', 'string', 'max:255'],
        ]);

        $attributes = array_intersect_key($data, array_flip(['device_name', 'ip_address', 'hidden']));

        if (isset($data['device_brand_name'])) {
            $model = DeviceAdmin::attributesFor($data['device_brand_name'], $data['device_product_type']);
            if ($model === null) {
                return $this->unknownProduct();
            }
            $attributes += $model;
        }

        $brand = $attributes['device_brand_name'] ?? $device->device_brand_name;
        $product = $attributes['device_product_type'] ?? $device->device_product_type;
        if (array_key_exists('ip_address', $attributes) && $attributes['ip_address'] === null && !DeviceAdmin::isVirtual($brand, $product)) {
            return response()->json(['message' => 'The address is required for this device.', 'errors' => ['ip_address' => ['The address is required for this device.']]], 422);
        }

        return response()->json(['data' => $this->payload(DeviceAdmin::update($device, $attributes))]);
    }

    public function destroy(Device $device): JsonResponse
    {
        DeviceAdmin::delete($device);

        return response()->json(['status' => 'ok']);
    }

    /** @return array<string, mixed> the list shape of `GET /api/devices` plus `hidden` */
    private function payload(Device $device): array
    {
        return (new DeviceListResource($device))->resolve() + ['hidden' => (bool) $device->hidden];
    }

    private function unknownProduct(): JsonResponse
    {
        return response()->json(['message' => DeviceAdmin::UNKNOWN_PRODUCT, 'errors' => ['device_product_type' => [DeviceAdmin::UNKNOWN_PRODUCT]]], 422);
    }
}
