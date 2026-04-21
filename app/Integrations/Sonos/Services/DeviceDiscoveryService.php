<?php

namespace App\Integrations\Sonos\Services;

use App\Integrations\Sonos\SonosDiscovery;
use App\Models\Device;
use App\Models\DeviceMeta;
use Carbon\Carbon;

class DeviceDiscoveryService
{
    public function __construct(private SonosDiscovery $discovery) {}

    /**
     * Discover and persist Sonos devices.
     *
     * @return array<int, Device>
     */
    public function discoverAndStore(): array
    {
        $devices = [];

        foreach ($this->discovery->discover() as $found) {
            $uuid = $found->meta['sonos_uuid'] ?? null;

            $device = null;
            if ($uuid) {
                $device = Device::whereHas('meta', fn ($q) => $q->where('key', 'sonos_uuid')->where('value', $uuid))->first();
            }
            if (!$device) {
                $device = Device::where('ip_address', $found->ip_address)->first();
            }

            $data = [
                'ip_address' => $found->ip_address,
                'device_brand_name' => $found->device_brand_name,
                'device_product_type' => $found->device_product_type,
                'device_name' => $found->device_name,
                'device_driver' => $found->device_driver,
                'device_driver_name' => $found->device_driver_name,
                'last_seen' => Carbon::now(),
            ];

            if ($device) {
                $device->fill($data)->save();
            } else {
                $device = Device::create($data);
            }

            if ($uuid) {
                DeviceMeta::updateOrCreate(
                    ['device_id' => $device->id, 'key' => 'sonos_uuid'],
                    ['value' => $uuid]
                );
            }

            $devices[] = $device;
        }

        return $devices;
    }
}
