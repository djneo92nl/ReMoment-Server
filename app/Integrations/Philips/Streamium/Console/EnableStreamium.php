<?php

namespace App\Integrations\Philips\Streamium\Console;

use App\Integrations\Philips\Streamium\MusicPlayerDriver;
use App\Integrations\Philips\Streamium\RemoteClient;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Console\Command;

class EnableStreamium extends Command
{
    protected $signature = 'streamium:enable {device : The id of the player (a UPnP device)} {--url= : The web remote\'s address if it is not http://{ip}:8889}';

    protected $description = 'Hand a Philips Streamium HiFi player (MCi900) to the Streamium driver: power, radio presets and the radio playing, on top of UPnP';

    public function handle(): int
    {
        $device = Device::find($this->argument('device'));

        if (!$device || !$device->ip_address) {
            $this->error('No such device, or it has no IP address.');

            return self::FAILURE;
        }

        $url = $this->option('url') ?: 'http://'.$device->ip_address.':'.RemoteClient::PORT;

        if (!(new RemoteClient($url))->reachable()) {
            $this->error("Nothing at {$url} answers as a Streamium web remote. Is the player switched on?");

            return self::FAILURE;
        }

        DeviceMeta::updateOrCreate(['device_id' => $device->id, 'key' => 'streamium_remote_url'], ['value' => $url]);
        $device->update(['device_driver' => MusicPlayerDriver::class, 'device_driver_name' => 'Streamium']);

        $this->info("{$device->device_name} now uses the Streamium driver (power toggle, radio presets, radio now playing). Restart the listeners (device:listen) to pick it up.");

        return self::SUCCESS;
    }
}
