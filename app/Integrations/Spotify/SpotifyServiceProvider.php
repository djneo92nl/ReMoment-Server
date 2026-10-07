<?php

namespace App\Integrations\Spotify;

use App\Integrations\Spotify\Console\SyncSpotifyLibrary;
use Illuminate\Support\ServiceProvider;

class SpotifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncSpotifyLibrary::class]);
        }
    }
}
