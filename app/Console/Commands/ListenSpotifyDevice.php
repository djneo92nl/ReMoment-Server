<?php

namespace App\Console\Commands;

use App\Integrations\Spotify\Services\DeviceListener;
use App\Integrations\Spotify\SpotifyDevice;
use App\Services\SpotifyTokenService;
use Illuminate\Console\Command;

class ListenSpotifyDevice extends Command
{
    protected $signature = 'device-spotify:listen';

    protected $description = 'Run a persistent Spotify playback listener for the virtual Spotify device.';

    public function handle(): void
    {
        $device = SpotifyDevice::findOrProvision();

        $listener = new DeviceListener(app(SpotifyTokenService::class));
        $listener->listen((string) $device->id);
    }
}
