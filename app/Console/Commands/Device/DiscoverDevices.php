<?php

namespace App\Console\Commands\Device;

use App\Domain\Device\DiscoveredDevicePersister;
use App\Services\NetworkDiscoveryService;
use Illuminate\Console\Command;

class DiscoverDevices extends Command
{
    protected $signature = 'device:discover
        {--brand=* : Only these discoverers (a part of the class name, e.g. sonos, ase, mozart)}
        {--host=* : IP to probe directly (repeatable), for networks where multicast does not reach this machine}';

    protected $description = 'Discover devices of every registered brand and store them (run out of Docker, multicast does not cross it)';

    public function handle(NetworkDiscoveryService $discovery, DiscoveredDevicePersister $persister): int
    {
        $classes = collect(config('devices.discoverers', []));

        if ($brands = array_map('strtolower', $this->option('brand'))) {
            $classes = $classes->filter(fn (string $class) => collect($brands)->contains(fn ($b) => str_contains(strtolower($class), $b)));

            if ($classes->isEmpty()) {
                $this->error('No discoverer matches: '.implode(', ', $brands));

                return self::FAILURE;
            }
        }

        $found = $discovery->discoverAll($classes->values()->all(), $this->option('host'));

        $this->info('Found devices: '.count($found));

        foreach ($found as $discovered) {
            $device = $persister->persist($discovered);

            $this->line(" - {$device->device_name} ({$device->ip_address}, {$device->device_brand_name})");
        }

        foreach ($discovery->diagnostics as $note) {
            $this->warn($note);
        }

        return self::SUCCESS;
    }
}
