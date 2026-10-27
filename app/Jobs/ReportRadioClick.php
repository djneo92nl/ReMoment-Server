<?php

namespace App\Jobs;

use App\Domain\Radio\RadioBrowser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Tells radio-browser.info a station of its directory was played (one call per listen, as its operators ask). */
class ReportRadioClick implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public string $stationUuid) {}

    public function handle(RadioBrowser $browser): void
    {
        $browser->click($this->stationUuid);
    }
}
