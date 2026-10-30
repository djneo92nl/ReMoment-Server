<?php

namespace App\Integrations\Philips\Streamium\Console;

use App\Domain\Radio\Relay\RelayDecision;
use App\Integrations\Philips\Streamium\MusicPlayerDriver;
use App\Integrations\Philips\Streamium\RemoteClient;
use App\Integrations\Upnp\StreamProfile;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Console\Command;

class EnableStreamium extends Command
{
    protected $signature = 'streamium:enable {device : The id of the player (a UPnP device)} {--url= : The web remote\'s address if it is not http://{ip}:8889} {--radio-relay=always : auto, always or never: play radio stations through the server (it cannot resolve names, do HTTPS or AAC itself)}';

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

        // What the unit decodes (the quality test of /settings/developer on an MCi900): lossless only up to 16-bit and 48 kHz, no AAC.
        // Files beyond that are sent as a proxy (see Upnp\StreamProfile).
        StreamProfile::setLimits($device, ['max_bit_depth' => 16, 'max_sample_rate' => 48000, 'deny_codecs' => ['aac'], 'no_tls' => true]);
        // It could not play a station by name (no name resolution, no HTTPS, no AAC): the radio relay serves it a plain HTTP MP3 by address.
        RelayDecision::setMode($device, (string) $this->option('radio-relay'));

        $this->info("{$device->device_name} now uses the Streamium driver (power toggle, inputs, radio presets, radio now playing; hi-res files and AAC are played from a proxy). Restart the listeners (device:listen) to pick it up.");

        return self::SUCCESS;
    }
}
