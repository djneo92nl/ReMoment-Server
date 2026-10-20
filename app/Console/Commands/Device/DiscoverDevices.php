<?php

namespace App\Console\Commands\Device;

use App\Domain\Device\DiscoveredDevicePersister;
use App\Domain\Device\DiscoveryInbox;
use App\Services\NetworkDiscoveryService;
use Illuminate\Console\Command;

class DiscoverDevices extends Command
{
    protected $signature = 'device:discover
        {--brand=* : Only these discoverers (a part of the class name, e.g. sonos, ase, mozart)}
        {--host=* : IP to probe directly (repeatable), for networks where multicast does not reach this machine}
        {--add : Store every device found as a device right away instead of listing it at /settings/devices}';

    protected $description = 'Discover devices of every registered brand and list them at /settings/devices to be added (run out of Docker, multicast does not cross it)';

    public function handle(NetworkDiscoveryService $discovery, DiscoveredDevicePersister $persister, DiscoveryInbox $inbox): int
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

        if ($this->option('add')) {
            foreach ($found as $discovered) {
                $device = $persister->persist($discovered);

                $this->line(" - {$device->device_name} ({$device->ip_address}, {$device->device_brand_name})");
            }
        } else {
            $counts = $inbox->record(array_values($found));

            foreach ($found as $discovered) {
                $this->line(" - {$discovered->device_name} ({$discovered->ip_address}, {$discovered->device_brand_name})");
            }

            $this->info("{$counts['new']} new to add, {$counts['known']} already known (refreshed). Add them at /settings/devices.");
        }

        foreach ($discovery->diagnostics as $note) {
            $this->warn($note);
        }

        return self::SUCCESS;
    }
}
