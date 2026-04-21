<?php

namespace App\Console\Commands;

use App\Integrations\BangOlufsen\Ase\AseDiscovery;
use App\Models\Device;
use App\Models\DeviceMeta;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DeviceDiscovery extends Command
{
    protected $signature = 'device:discovery';

    protected $description = 'Starts device discovery to populate our known local devices (run out of Docker)';

    public function __construct(private AseDiscovery $discovery)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $discovered = $this->discovery->discover();

        $this->line('Found ASE devices: '.count($discovered));

        foreach ($discovered as $found) {
            $device = null;

            if ($upnpUuid = $found->meta['upnp_uuid'] ?? null) {
                $device = Device::whereHas('meta', fn ($q) => $q->where('key', 'upnp_uuid')->where('value', $upnpUuid))->first();
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

            if ($upnpUuid) {
                DeviceMeta::updateOrCreate(
                    ['device_id' => $device->id, 'key' => 'upnp_uuid'],
                    ['value' => $upnpUuid]
                );
            }

            $this->line(" - {$found->device_name} ({$found->ip_address})");
        }

        return self::SUCCESS;
    }
}
