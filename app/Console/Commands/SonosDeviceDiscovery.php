<?php

namespace App\Console\Commands;

use App\Integrations\Sonos\Services\DeviceDiscoveryService;
use Illuminate\Console\Command;

class SonosDeviceDiscovery extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'device:sonos-discovery {--host=* : Sonos IP to probe directly (repeatable), for networks where multicast does not reach this machine}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Discover Sonos devices and persist them.';

    public function __construct(private DeviceDiscoveryService $discovery)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $devices = $this->discovery->discoverAndStore($this->option('host'));

        $this->info('Found Sonos devices: '.count($devices));

        foreach ($devices as $device) {
            $this->line(" - {$device->device_name} ({$device->ip_address})");
        }

        foreach ($this->discovery->diagnostics() as $note) {
            $this->warn($note);
        }

        return self::SUCCESS;
    }
}
