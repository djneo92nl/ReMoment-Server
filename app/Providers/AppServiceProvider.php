<?php

namespace App\Providers;

use App\Domain\Library\LibraryCache;
use App\Events\Device\BatteryUpdated;
use App\Events\Device\DeviceStateChanged;
use App\Events\Device\MultiRoomUpdated;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\PlaybackModesUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\QueueUpdated;
use App\Events\Device\VolumeUpdated;
use App\Listeners\Device\ClosePlaybackHistory;
use App\Listeners\Device\DispatchArtworkProcessing;
use App\Listeners\Device\HoldOwnModesWhileSpotifyRouted;
use App\Listeners\Device\PublishBatteryToMqtt;
use App\Listeners\Device\PublishModesToMqtt;
use App\Listeners\Device\PublishMultiRoomToMqtt;
use App\Listeners\Device\PublishNowPlayingToMqtt;
use App\Listeners\Device\PublishProgressToMqtt;
use App\Listeners\Device\PublishQueueToMqtt;
use App\Listeners\Device\PublishStateToMqtt;
use App\Listeners\Device\PublishVolumeToMqtt;
use App\Listeners\Device\StorePlaybackHistory;
use App\Listeners\Device\SwitchToDefaultSource;
use App\Listeners\Device\SyncGroupMirror;
use App\Listeners\Device\SyncNowPlayingDataWithState;
use App\Listeners\Device\UpdateDeviceCache;
use App\Services\MqttService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MqttService::class);
        $this->app->scoped(\App\Support\SelectedDevice::class);
        $this->app->scoped(\App\Domain\Artwork\ArtworkEntryMemo::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // MusicBrainz asks for at most one request per second; shared by every enrichment worker.
        RateLimiter::for('musicbrainz', fn () => Limit::perSecond(1));
        // TheAudioDB's free key allows about 30 requests a minute, Discogs 60 with a token.
        RateLimiter::for('audiodb', fn () => Limit::perMinute(25));
        RateLimiter::for('discogs', fn () => Limit::perMinute(50));
        // LRCLIB has no published limit but answers 503 when hammered; one request a second, shared by every worker.
        RateLimiter::for('lrclib', fn () => Limit::perSecond(1));

        // Spotify answers 429 for a few seconds' worth of bursts; Last.fm allows about 5 requests a second per key.
        RateLimiter::for('spotify', fn () => Limit::perSecond(3));
        RateLimiter::for('lastfm', fn () => Limit::perSecond(4));

        // Writes to the library tables mark the cached library overviews stale (LibraryCache).
        Event::listen(QueryExecuted::class, fn (QueryExecuted $query) => LibraryCache::observe($query->sql));

        Event::listen(NowPlayingUpdated::class, [UpdateDeviceCache::class, 'handle']);
        Event::listen(NowPlayingUpdated::class, [StorePlaybackHistory::class, 'handle']);
        Event::listen(NowPlayingUpdated::class, PublishNowPlayingToMqtt::class);
        Event::listen(NowPlayingUpdated::class, DispatchArtworkProcessing::class);
        // After UpdateDeviceCache: it reads the cache those events just wrote.
        Event::listen(NowPlayingUpdated::class, SyncGroupMirror::class);

        Event::listen(ProgressUpdated::class, UpdateDeviceCache::class);
        Event::listen(ProgressUpdated::class, PublishProgressToMqtt::class);
        Event::listen(ProgressUpdated::class, SyncGroupMirror::class);
        Event::listen(NowPlayingEnded::class, UpdateDeviceCache::class);
        Event::listen(NowPlayingEnded::class, [ClosePlaybackHistory::class, 'handle']);
        Event::listen(VolumeUpdated::class, UpdateDeviceCache::class);
        Event::listen(VolumeUpdated::class, PublishVolumeToMqtt::class);
        Event::listen(BatteryUpdated::class, UpdateDeviceCache::class);
        Event::listen(BatteryUpdated::class, PublishBatteryToMqtt::class);
        // Must stay first: it holds back a speaker's own modes while Spotify is routed to it.
        Event::listen(PlaybackModesUpdated::class, HoldOwnModesWhileSpotifyRouted::class);
        Event::listen(PlaybackModesUpdated::class, UpdateDeviceCache::class);
        Event::listen(PlaybackModesUpdated::class, PublishModesToMqtt::class);

        Event::listen(QueueUpdated::class, PublishQueueToMqtt::class);

        Event::listen(MultiRoomUpdated::class, UpdateDeviceCache::class);
        Event::listen(MultiRoomUpdated::class, PublishMultiRoomToMqtt::class);
        Event::listen(DeviceStateChanged::class, PublishStateToMqtt::class);
        Event::listen(DeviceStateChanged::class, SyncNowPlayingDataWithState::class);
        Event::listen(DeviceStateChanged::class, SwitchToDefaultSource::class);
        Event::listen(DeviceStateChanged::class, SyncGroupMirror::class);
    }
}
