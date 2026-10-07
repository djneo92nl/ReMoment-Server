<?php

namespace App\Domain\Device;

use App\Models\Device;
use App\Models\DeviceMeta;
use Carbon\Carbon;

/**
 * Stores what a discoverer found: updates the device it already knows (matched on one of the identifiers in
 * `DiscoveredDevice::$meta`, else on IP within the same brand) or creates it.
 */
class DiscoveredDevicePersister
{
    public function persist(DiscoveredDevice $found): Device
    {
        $device = $this->existing($found);

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

        foreach ($found->meta as $key => $value) {
            DeviceMeta::updateOrCreate(['device_id' => $device->id, 'key' => $key], ['value' => $value]);
        }

        return $device;
    }

    private function existing(DiscoveredDevice $found): ?Device
    {
        foreach ($found->meta as $key => $value) {
            $device = Device::whereHas('meta', fn ($q) => $q->where('key', $key)->where('value', $value))->first();

            if ($device) {
                return $device;
            }
        }

        // IPs get reused by DHCP: never take over a device of another brand.
        return Device::where('ip_address', $found->ip_address)
            ->where('device_brand_name', $found->device_brand_name)
            ->first();
    }
}
