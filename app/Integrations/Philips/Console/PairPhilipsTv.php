<?php

namespace App\Integrations\Philips\Console;

use App\Integrations\Philips\JointspaceClient;
use App\Integrations\Philips\Pairing;
use App\Models\Device;
use Illuminate\Console\Command;

class PairPhilipsTv extends Command
{
    protected $signature = 'philips:pair {device : The id of the TV (a UPnP device)}';

    protected $description = 'Pair a Philips Android TV: it shows a PIN, then standby, apps and the active app work on top of UPnP';

    public function handle(): int
    {
        $device = Device::find($this->argument('device'));

        if (!$device || !$device->ip_address) {
            $this->error('No such device, or it has no IP address.');

            return self::FAILURE;
        }

        $client = new JointspaceClient($device->ip_address);

        try {
            $pending = Pairing::begin($client);
        } catch (\Throwable $e) {
            $this->error("The TV did not answer ({$device->ip_address}): {$e->getMessage()}. Is it switched on?");

            return self::FAILURE;
        }

        $pin = $this->ask('PIN shown on the TV');

        try {
            Pairing::finish($device, $client, $pending, (string) $pin);
        } catch (\Throwable $e) {
            $this->error("Pairing failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Paired. {$device->device_name} now uses the Philips driver (standby, apps, active app).");

        return self::SUCCESS;
    }
}
