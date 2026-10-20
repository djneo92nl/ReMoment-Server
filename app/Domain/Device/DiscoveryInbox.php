<?php

namespace App\Domain\Device;

use App\Models\Device;
use App\Models\DiscoveredDeviceRecord;
use Carbon\Carbon;

/**
 * What scans found and nobody has added yet. Scans (web, `device:discover`, the scheduled one) only record here;
 * a device becomes a `Device` when someone adds it. A find that is already a device only refreshes that device.
 */
class DiscoveryInbox
{
    public function __construct(private readonly DiscoveredDevicePersister $persister) {}

    /**
     * @param  DiscoveredDevice[]  $found
     * @return array{new: int, known: int}
     */
    public function record(array $found): array
    {
        $counts = ['new' => 0, 'known' => 0];

        foreach ($found as $device) {
            if ($this->persister->known($device)) {
                $this->persister->persist($device);
                DiscoveredDeviceRecord::where('ip_address', $device->ip_address)->delete();
                $counts['known']++;

                continue;
            }

            $row = DiscoveredDeviceRecord::firstOrNew(['ip_address' => $device->ip_address]);
            $isNew = !$row->exists;

            $row->fill([
                'device_name' => $row->exists ? $row->device_name : $device->device_name,
                'device_brand_name' => $device->device_brand_name,
                'device_product_type' => $device->device_product_type,
                'device_driver' => $device->device_driver,
                'device_driver_name' => $device->device_driver_name,
                'meta' => $device->meta,
                'last_seen_at' => Carbon::now(),
            ])->save();

            $counts['new'] += $isNew ? 1 : 0;
        }

        return $counts;
    }

    public function add(DiscoveredDeviceRecord $record, ?string $name = null): Device
    {
        $device = $this->persister->persist($record->toDiscoveredDevice($name));
        $record->delete();

        return $device;
    }

    public function ignore(DiscoveredDeviceRecord $record): void
    {
        $record->update(['ignored_at' => Carbon::now()]);
    }

    public function restore(DiscoveredDeviceRecord $record): void
    {
        $record->update(['ignored_at' => null]);
    }
}
