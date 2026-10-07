<?php

namespace App\Console\Commands\Device;

use App\Domain\Device\DeviceListeners;
use App\Models\Device;
use Illuminate\Console\Command;

class ListenDevice extends Command
{
    protected $signature = 'device:listen-single {id}';

    protected $description = 'Run the persistent listener of one device, whatever its brand.';

    public function handle(): int
    {
        $device = Device::findOrFail($this->argument('id'));
        $listener = DeviceListeners::for($device);

        if ($listener === null) {
            $this->error("No listener available for {$device->device_name} (driver {$device->device_driver}).");

            return self::FAILURE;
        }

        $this->info("Listening to {$device->device_name}".($device->ip_address ? " ({$device->ip_address})" : ''));
        $listener->listen((string) $device->id);

        return self::SUCCESS;
    }
}
