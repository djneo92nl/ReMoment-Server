<?php

namespace App\Console\Commands\Radio;

use App\Domain\Radio\StreamChecker;
use App\Models\RadioStation;
use Illuminate\Console\Command;

class RefreshRadioStations extends Command
{
    protected $signature = 'radio:refresh';

    protected $description = 'Check the stream of every radio station; look up moved ones again at radio-browser.info and flag dead ones (meta stream_status)';

    public function handle(StreamChecker $checker): int
    {
        $counts = ['ok' => 0, 'moved' => 0, 'dead' => 0];

        RadioStation::with('meta')->get()->each(function (RadioStation $station) use ($checker, &$counts) {
            if ($station->streamUrl() !== null) {
                $counts[$checker->check($station)]++;
            }
        });

        $this->info("Streams: {$counts['ok']} ok, {$counts['moved']} moved, {$counts['dead']} dead.");

        return self::SUCCESS;
    }
}
