<?php

namespace App\Providers;

use App\Events\Device\DeviceStateChanged;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\PlaybackModesUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;
use App\Listeners\Device\ClosePlaybackHistory;
use App\Listeners\Device\DispatchArtworkProcessing;
use App\Listeners\Device\PublishModesToMqtt;
use App\Listeners\Device\PublishNowPlayingToMqtt;
use App\Listeners\Device\PublishProgressToMqtt;
use App\Listeners\Device\PublishStateToMqtt;
use App\Listeners\Device\PublishVolumeToMqtt;
use App\Listeners\Device\StorePlaybackHistory;
use App\Listeners\Device\UpdateDeviceCache;
use App\Services\MqttService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MqttService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(NowPlayingUpdated::class, [UpdateDeviceCache::class, 'handle']);
        Event::listen(NowPlayingUpdated::class, [StorePlaybackHistory::class, 'handle']);
        Event::listen(NowPlayingUpdated::class, PublishNowPlayingToMqtt::class);
        Event::listen(NowPlayingUpdated::class, DispatchArtworkProcessing::class);

        Event::listen(ProgressUpdated::class, UpdateDeviceCache::class);
        Event::listen(ProgressUpdated::class, PublishProgressToMqtt::class);
        Event::listen(NowPlayingEnded::class, UpdateDeviceCache::class);
        Event::listen(NowPlayingEnded::class, [ClosePlaybackHistory::class, 'handle']);
        Event::listen(VolumeUpdated::class, UpdateDeviceCache::class);
        Event::listen(VolumeUpdated::class, PublishVolumeToMqtt::class);
        Event::listen(PlaybackModesUpdated::class, UpdateDeviceCache::class);
        Event::listen(PlaybackModesUpdated::class, PublishModesToMqtt::class);
        Event::listen(DeviceStateChanged::class, PublishStateToMqtt::class);
    }
}
