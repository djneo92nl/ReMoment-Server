<?php

namespace App\Providers;

use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Listeners\Device\StorePlaybackHistory;
use App\Listeners\Device\UpdateDeviceCache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(NowPlayingUpdated::class, [UpdateDeviceCache::class, 'handle']);
        Event::listen(NowPlayingUpdated::class, [StorePlaybackHistory::class, 'handle']);

        Event::listen(ProgressUpdated::class, UpdateDeviceCache::class);
        Event::listen(NowPlayingEnded::class, UpdateDeviceCache::class);
    }
}
