<?php

namespace App\Jobs;

use App\Domain\Radio\NowPlayingProviders;
use App\Domain\Radio\RadioPlayLog;
use App\Domain\Radio\StreamTitles;
use App\Models\RadioStation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Reads a station's current title through its {@see NowPlayingProviders} and schedules itself again while a device still plays the station. */
class PollRadioTitle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const INTERVAL_SECONDS = 15;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public int $stationId) {}

    public function handle(NowPlayingProviders $providers): void
    {
        $station = RadioStation::with('meta')->find($this->stationId);

        if ($station === null || !$providers->supports($station) || !StreamTitles::active($station->id)) {
            StreamTitles::chainEnded($this->stationId);

            return;
        }

        [$source, $title] = $providers->fetch($station) ?? ['icy', null];
        StreamTitles::put($station->id, $title, $source);

        if ($title !== null) {
            RadioPlayLog::record($station, $source, $title);
        }

        StreamTitles::chainContinues($station->id);
        static::dispatch($station->id)->delay(now()->addSeconds(self::INTERVAL_SECONDS));
    }
}
