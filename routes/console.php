<?php

use App\Domain\Health\Heartbeat;
use App\Domain\Health\QueueWatchdog;
use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('device:sync-sources')->weekly();

// Finds new devices for the list at /settings/devices; never adds one (DEVICE_AUTO_DISCOVER=false turns it off).
if (config('devices.auto_discover')) {
    Schedule::command('device:discover')->hourly()->withoutOverlapping()->runInBackground();
}

Schedule::command('spotify:sync-library')->daily();
Schedule::command('artwork:backfill')->daily();
Schedule::command('artwork:prerender')->daily();
Schedule::command('library:enrich')->daily();
Schedule::command('playlists:refresh-smart')->daily();
Schedule::command('radio:refresh')->weekly();
Schedule::command('radio:fix-artwork')->weekly();
Schedule::call(fn () => \App\Domain\Radio\RadioPlayLog::prune())->daily()->name('radio-plays-prune');

// Audio analysis (loudness, fingerprints, BPM/key/mood) of the own files; a bounded batch on its own queue, off by default (ANALYSIS_ENABLED).
Schedule::command('library:analyze')->dailyAt('02:00');

// Picks up files added, changed or removed outside the app in the storage locations (queued; see docs/architecture/file-storage.md).
Schedule::command('library:scan-files --queue')->dailyAt('03:30');

// Folds duplicate artists/albums/tracks together (ids of merged records disappear), so weekly and off-hours.
Schedule::command('library:merge-duplicates --force')->weeklyOn(0, '04:00')->withoutOverlapping();

// Fills and refreshes the cached library overviews (LibraryCache), so a visit never pays for a cold or stale one.
Schedule::command('library:warm')->everyFiveMinutes()->withoutOverlapping()->runInBackground();

// Health heartbeats — see App\Domain\Health\Heartbeat and /settings/health.
Schedule::call(function () {
    Heartbeat::beat(Heartbeat::SCHEDULER);
    QueueHeartbeat::dispatch();
})->everyMinute()->name('health-heartbeat');

// Kills a hung default-queue worker so supervisor starts a new one (a stale queue beat while the scheduler beats).
Schedule::call(fn () => (new QueueWatchdog)->run())->everyMinute()->name('queue-watchdog');
