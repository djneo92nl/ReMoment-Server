<?php

namespace App\Services;

use App\Domain\Device\DiscoveredDevice;
use App\Integrations\Contracts\DiscoveryInterface;
use App\Integrations\Contracts\HostHintedDiscovery;
use App\Models\Device;

class NetworkDiscoveryService
{
    /** @var string[] Notes from the discoverers of the last run. */
    public array $diagnostics = [];

    /**
     * Run the given discoverers (or all registered ones) and return devices not yet in the database.
     *
     * @param  string[]|null  $discovererClasses
     * @param  string[]  $hosts  IPs handed to discoverers that can probe hosts directly
     * @return DiscoveredDevice[]
     */
    public function discover(?array $discovererClasses = null, array $hosts = []): array
    {
        $discovererClasses ??= config('devices.discoverers', []);

        $all = [];

        foreach ($discovererClasses as $class) {
            /** @var DiscoveryInterface $discoverer */
            $discoverer = app()->make($class);

            if ($discoverer instanceof HostHintedDiscovery) {
                $discoverer->withHosts($hosts);
            }

            foreach ($discoverer->discover() as $device) {
                // Deduplicate by IP — later entry wins
                $all[$device->ip_address] = $device;
            }

            if ($discoverer instanceof HostHintedDiscovery) {
                $this->diagnostics = [...$this->diagnostics, ...$discoverer->diagnostics()];
            }
        }

        // Filter out IPs already stored in the database
        $existingIps = Device::whereIn('ip_address', array_keys($all))->pluck('ip_address')->all();

        return array_values(
            array_filter($all, fn (DiscoveredDevice $d) => !in_array($d->ip_address, $existingIps))
        );
    }
}
