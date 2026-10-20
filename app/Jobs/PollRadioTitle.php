<?php

namespace App\Jobs;

use App\Domain\Radio\IcyReader;
use App\Domain\Radio\StreamTitles;
use App\Models\RadioStation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Reads a station's ICY title and schedules itself again while a device still plays the station. */
class PollRadioTitle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const INTERVAL_SECONDS = 15;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public int $stationId) {}

    public function handle(IcyReader $reader): void
    {
        $station = RadioStation::with('meta')->find($this->stationId);
        $url = $station?->getMeta('url');

        if ($station === null || $url === null || !StreamTitles::active($station->id)) {
            StreamTitles::chainEnded($this->stationId);

            return;
        }

        try {
            StreamTitles::put($station->id, $reader->title($url));
        } catch (\Throwable) {
            // A stream that does not answer simply has no title for now.
        }

        StreamTitles::chainContinues($station->id);
        static::dispatch($station->id)->delay(now()->addSeconds(self::INTERVAL_SECONDS));
    }
}
