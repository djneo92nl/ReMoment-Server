<?php

namespace App\Integrations\Sonos\Services;

use App\Integrations\Sonos\MusicPlayerDriver;
use App\Models\Device;
use App\Models\DeviceMeta;
use Carbon\Carbon;
use duncan3dc\Sonos\Devices\Device as SonosDevice;
use duncan3dc\Sonos\Network;

class DeviceDiscoveryService
{
    public function __construct(private ?Network $network = null)
    {
        $this->network = $network ?? new Network();
    }

    /**
     * @return array<int, Device>
     */
    public function discoverAndStore(): array
    {
        $devices = [];

        foreach ($this->network->getSpeakers() as $speaker) {
            $ip = $speaker->getIp();
            $uuid = $speaker->getUuid();

            $device = Device::whereHas('meta', function ($query) use ($uuid) {
                $query->where('key', 'sonos_uuid')
                    ->where('value', $uuid);
            })->first();

            if (!$device) {
                $device = Device::where('ip_address', $ip)->first();
            }

            $sonosDevice = new SonosDevice($ip);
            $model = $sonosDevice->getModel();

            $deviceData = [
                'ip_address' => $ip,
                'device_brand_name' => 'Sonos',
                'device_product_type' => $model,
                'device_name' => $speaker->getName() ?: ($speaker->getRoom() ?: $ip),
                'device_driver' => MusicPlayerDriver::class,
                'device_driver_name' => 'Sonos',
                'last_seen' => Carbon::now(),
            ];

            if ($device) {
                $device->fill($deviceData);
                $device->save();
            } else {
                $device = Device::create($deviceData);
            }

            DeviceMeta::updateOrCreate(
                [
                    'device_id' => $device->id,
                    'key' => 'sonos_uuid',
                ],
                [
                    'value' => $uuid,
                ]
            );

            $devices[] = $device;
        }

        return $devices;
    }
}
