<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;

class ListenAseSingleDevice extends Command
{
    protected $signature = 'device-ase:listen-single {id}';

    protected $description = 'Run a single persistent device listener for a ASE device.';

    public function handle()
    {
        $id = $this->argument('id');

        $device = Device::find($id);
        $url = 'http://'.$device->ip_address.':8080/BeoNotify/Notifications';

        $listener = new \App\Integrations\BangOlufsen\Ase\Services\DeviceListener($url);
        $listener->listen($id);
    }
}
