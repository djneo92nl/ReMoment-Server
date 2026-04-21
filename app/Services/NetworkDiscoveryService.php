<?php

namespace App\Services;

use App\Domain\Device\DiscoveredDevice;
use App\Integrations\Contracts\DiscoveryInterface;
use App\Models\Device;

class NetworkDiscoveryService
{
    /**
     * Run all registered discoverers and return devices not yet in the database.
     *
     * @return DiscoveredDevice[]
     */
    public function discover(): array
    {
        $discovererClasses = config('devices.discoverers', []);

        $all = [];

        foreach ($discovererClasses as $class) {
            /** @var DiscoveryInterface $discoverer */
            $discoverer = app()->make($class);

            foreach ($discoverer->discover() as $device) {
                // Deduplicate by IP — later entry wins
                $all[$device->ip_address] = $device;
            }
        }

        // Filter out IPs already stored in the database
        $existingIps = Device::whereIn('ip_address', array_keys($all))->pluck('ip_address')->all();

        return array_values(
            array_filter($all, fn (DiscoveredDevice $d) => !in_array($d->ip_address, $existingIps))
        );
    }
}
