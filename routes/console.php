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

// Health heartbeats — see App\Domain\Health\Heartbeat and /settings/health.
Schedule::call(function () {
    Heartbeat::beat(Heartbeat::SCHEDULER);
    QueueHeartbeat::dispatch();
})->everyMinute()->name('health-heartbeat');
