<?php

namespace App\Http\Controllers\Api;

use App\Domain\Device\DeviceScan;
use App\Domain\Device\DiscoveryInbox;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DeviceListResource;
use App\Models\DiscoveredDeviceRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The discovered-devices inbox for apps (docs/api/admin-devices.md), admin token only: start a scan, see what it found,
 * add, ignore or restore a find. The same inbox as `/settings/devices` (`DiscoveryInbox`).
 */
class AdminDiscoveryController extends Controller
{
    public function index(): JsonResponse
    {
        $records = DiscoveredDeviceRecord::orderBy('device_name')->get();

        return response()->json([
            'scan' => DeviceScan::status(),
            'data' => $records->whereNull('ignored_at')->map(fn ($r) => $this->payload($r))->values(),
            'ignored' => $records->whereNotNull('ignored_at')->map(fn ($r) => $this->payload($r))->values(),
        ]);
    }

    /** Queues a scan (202), or reports the one that is running. Poll `GET /admin/discovery` for the result. */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate(['hosts' => ['sometimes', 'array', 'max:20'], 'hosts.*' => ['string', 'max:255']]);

        $hosts = DeviceScan::parseHosts($data['hosts'] ?? []);

        return response()->json(['scan' => DeviceScan::start($hosts)], 202);
    }

    public function add(Request $request, DiscoveredDeviceRecord $record, DiscoveryInbox $inbox): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255']]);

        $device = $inbox->add($record, $data['name'] ?? null);

        return response()->json(['data' => (new DeviceListResource($device))->resolve() + ['hidden' => (bool) $device->hidden]], 201);
    }

    /** Adds every find that is not ignored, under the name it was found with. */
    public function addAll(DiscoveryInbox $inbox): JsonResponse
    {
        $ids = [];

        foreach (DiscoveredDeviceRecord::whereNull('ignored_at')->get() as $record) {
            $ids[] = $inbox->add($record)->id;
        }

        return response()->json(['added' => count($ids), 'device_ids' => $ids]);
    }

    public function ignore(DiscoveredDeviceRecord $record, DiscoveryInbox $inbox): JsonResponse
    {
        $inbox->ignore($record);

        return response()->json(['data' => $this->payload($record->refresh())]);
    }

    public function restore(DiscoveredDeviceRecord $record, DiscoveryInbox $inbox): JsonResponse
    {
        $inbox->restore($record);

        return response()->json(['data' => $this->payload($record->refresh())]);
    }

    /** @return array<string, mixed> */
    private function payload(DiscoveredDeviceRecord $record): array
    {
        return [
            'id' => $record->id,
            'device_name' => $record->device_name,
            'ip_address' => $record->ip_address,
            'device_brand_name' => $record->device_brand_name,
            'device_product_type' => $record->device_product_type,
            'device_driver_name' => $record->device_driver_name,
            'last_seen_at' => $record->last_seen_at?->toIso8601String(),
            'ignored' => $record->ignored_at !== null,
        ];
    }
}
