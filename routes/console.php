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
    Schedule::command('device:discover')->hourly()->withoutOverlapping(120)->runInBackground();
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
// The overlap lock expires after 10 minutes: the default is 24 h, and a run killed by a restart never clears it (Redis survives), so the warm-up would stay off for a day.
Schedule::command('library:warm')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();

// Nightly backup of the database and settings, keeping the last BACKUP_KEEP archives (docs/architecture/backup-restore.md); /settings/health warns when it stops.
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping();

// failed_jobs only grows otherwise; a month is enough to look at a failure.
Schedule::command('queue:prune-failed --hours=720')->dailyAt('04:30');

// Health heartbeats — see App\Domain\Health\Heartbeat and /settings/health.
Schedule::call(function () {
    Heartbeat::beat(Heartbeat::SCHEDULER);
    QueueHeartbeat::dispatch();
})->everyMinute()->name('health-heartbeat');

// Kills a hung default-queue worker so supervisor starts a new one (a stale queue beat while the scheduler beats).
Schedule::call(fn () => (new QueueWatchdog)->run())->everyMinute()->name('queue-watchdog');
