<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use Remoment\MozartDriver\Services\DeviceListener;

class ListenMozartSingleDevice extends Command
{
    protected $signature = 'device-mozart:listen-single {id}';

    protected $description = 'Run a single persistent device listener for a Mozart device.';

    public function handle()
    {
        $id = $this->argument('id');

        $device = Device::find($id);

        $this->info("Listening to {$device->device_name} ({$device->ip_address})");

        $listener = new DeviceListener($device->ip_address, config('mozart.ws_port', 9000));
        $listener->onError(fn (\Throwable $e) => $this->error('[error] '.$e->getMessage()));
        $listener->listen($id);
    }
}
