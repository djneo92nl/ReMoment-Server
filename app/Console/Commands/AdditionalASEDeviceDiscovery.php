<?php

namespace App\Console\Commands;

use App\Integrations\BangOlufsen\Common\MozartDiscoveryService;
use Illuminate\Console\Command;

class AdditionalASEDeviceDiscovery extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'device:additional-bo-device-discovery';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    public function __construct(private MozartDiscoveryService $discovery)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     * _bangolufsen._tcp.local.
     */
    public function handle()
    {

        $devices = $this->discovery->discover();
        dump($devices);
    }
}
