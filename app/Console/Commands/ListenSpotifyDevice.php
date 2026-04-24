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

        $this->info('Listening to Spotify (device '.$device->id.')');

        $listener = new DeviceListener(app(SpotifyTokenService::class));
        $listener->onError(fn (\Throwable $e) => $this->error('[error] '.$e->getMessage()));
        $listener->listen((string) $device->id);
    }
}
