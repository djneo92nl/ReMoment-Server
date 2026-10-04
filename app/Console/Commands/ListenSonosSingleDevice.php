<?php

namespace App\Console\Commands;

use App\Integrations\Sonos\Services\DeviceListener;
use App\Models\Device;
use Illuminate\Console\Command;

class ListenSonosSingleDevice extends Command
{
    protected $signature = 'device-sonos:listen-single {id}';

    protected $description = 'Run a single persistent device listener for a Sonos device.';

    public function handle()
    {
        $id = $this->argument('id');

        $device = Device::findOrFail($id);

        $this->info("Listening to {$device->device_name} ({$device->ip_address})");

        (new DeviceListener($device->ip_address))->listen($id);
    }
}
