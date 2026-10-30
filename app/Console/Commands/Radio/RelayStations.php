<?php

namespace App\Console\Commands\Radio;

use App\Domain\Analysis\Tools;
use App\Domain\Radio\Relay\FfmpegProcess;
use App\Domain\Radio\Relay\IcecastStatus;
use App\Domain\Radio\Relay\RadioRelay;
use App\Domain\Radio\Relay\RelayManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs the radio relay: one ffmpeg per wanted station, from a station's address to a live mount on Icecast.
 * Meant to be supervised like `device:listen`; it idles (and says so once) while `RADIO_RELAY_ENABLED` is off.
 * See docs/architecture/radio-relay.md.
 */
class RelayStations extends Command
{
    protected $signature = 'radio:relay {--station=* : Station ids to keep relayed while this runs, to try it by hand (runs even when the relay is off)}';

    protected $description = 'Relay radio stations a renderer cannot play itself: ffmpeg converts them to MP3 on the local Icecast, on demand.';

    private bool $stopping = false;

    public function handle(): int
    {
        $pinned = array_map('intval', (array) $this->option('station'));

        if (!RadioRelay::enabled() && $pinned === []) {
            return $this->idleWhileOff();
        }

        if (!Tools::available(Tools::ffmpeg())) {
            $this->error('ffmpeg is not available ('.Tools::ffmpeg().').');

            return self::FAILURE;
        }

        $manager = new RelayManager(FfmpegProcess::launch(...), new IcecastStatus, Tools::ffmpeg(), fn () => now()->timestamp, $pinned);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopping = true);
            pcntl_signal(SIGINT, fn () => $this->stopping = true);
        }

        $this->info('Radio relay running'.($pinned ? ' for station(s) '.implode(', ', $pinned) : '').'.');

        while (!$this->stopping) {
            try {
                $manager->tick();
            } catch (\Throwable $e) {
                Log::error('Radio relay: '.$e->getMessage());
            }

            sleep(1);
        }

        $manager->stopAll();

        return self::SUCCESS;
    }

    /** A supervised program that exits would be restarted in a loop: wait instead, so turning the relay on needs only a restart. */
    private function idleWhileOff(): int
    {
        $this->info('The radio relay is off (RADIO_RELAY_ENABLED=false); waiting.');

        while (true) {
            sleep(60);
        }
    }
}
