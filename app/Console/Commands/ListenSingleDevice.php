<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ListenSingleDevice extends Command
{
    protected $signature = 'device:listen-single {id}';

    protected $description = 'Run a single persistent device listener.';

    public function handle()
    {
        $id = $this->argument('id');

        $devices = [
            'livingroom' => 'http://192.168.1.25:8080/BeoNotify/Notifications',
        ];

        if (!isset($devices[$id])) {
            $this->error('Unknown device.');

            return;
        }

        $listener = new \App\DeviceDrivers\BangOlufsen\Ase\Services\DeviceListener($devices[$id]);
        $listener->listen($id);
    }
}
