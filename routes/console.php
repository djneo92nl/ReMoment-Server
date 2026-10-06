<?php

use App\Domain\Health\Heartbeat;
use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('devices:sync-sources')->weekly();
Schedule::command('library:sync-spotify')->daily();
Schedule::command('library:backfill-artwork')->daily();
Schedule::command('artwork:prerender')->daily();
Schedule::command('library:backfill-lastfm')->daily();
Schedule::command('library:enrich')->daily();
Schedule::command('library:artist-images')->daily();

// Folds duplicate artists/albums/tracks together (ids of merged records disappear), so weekly and off-hours.
Schedule::command('library:merge-duplicates --force')->weeklyOn(0, '04:00')->withoutOverlapping();

// Health heartbeats — see App\Domain\Health\Heartbeat and /settings/health.
Schedule::call(function () {
    Heartbeat::beat(Heartbeat::SCHEDULER);
    QueueHeartbeat::dispatch();
})->everyMinute()->name('health-heartbeat');
