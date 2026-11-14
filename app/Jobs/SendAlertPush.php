<?php

namespace App\Jobs;

use App\Domain\Push\Push;
use App\Models\Alert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Sends one alert to every subscribed browser (docs/frontend/pwa.md). Once per alert id and severity, so a retry or a second
 * raise does not notify twice; a rise from warning to critical does. Runs on the default queue, which every profile has.
 */
class SendAlertPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $alertId, public string $severity) {}

    public function handle(): void
    {
        $alert = Alert::find($this->alertId);
        if (!$alert || !$alert->isActive() || !in_array($alert->severity, Push::SEVERITIES, true)) {
            return;
        }
        if (!Cache::add("push:alert:{$alert->id}:{$alert->severity}", 1, now()->addDay())) {
            return;
        }

        Push::deliver(Push::payload($alert));
    }
}
