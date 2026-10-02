<?php

namespace App\Console\Commands;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Easter egg: an ASCII equalizer that dances along with whatever is playing.
 */
class Vibe extends Command
{
    protected $signature = 'remoment:vibe {--device= : Device ID to vibe along with} {--once : Render a single frame}';

    protected $description = 'Vibe along with whatever is playing';

    protected $hidden = true;

    private const BLOCKS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    private const BARS = 12;

    public function handle(): int
    {
        $levels = array_fill(0, self::BARS, 0);
        $nowPlaying = $this->nowPlaying();
        $lastLookup = microtime(true);
        $drawn = false;

        while (true) {
            if (microtime(true) - $lastLookup > 3) {
                $nowPlaying = $this->nowPlaying();
                $lastLookup = microtime(true);
            }

            $levels = $nowPlaying
                ? array_map(fn ($l) => max(0, min(7, $l + random_int(-2, 2))), $levels)
                : array_fill(0, self::BARS, 0);

            if ($drawn) {
                $this->output->write("\e[2A");
            }

            $bars = implode(' ', array_map(fn ($l) => self::BLOCKS[$l], $levels));
            $this->output->writeln("\e[2K  <fg=magenta>{$bars}</>");
            $this->output->writeln("\e[2K  ".$this->caption($nowPlaying));
            $drawn = true;

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            usleep(120_000);
        }
    }

    private function nowPlaying(): ?NowPlaying
    {
        if ($id = $this->option('device')) {
            return DeviceCache::getState((int) $id) === State::Playing
                ? DeviceCache::getNowPlaying((int) $id)
                : null;
        }

        return DeviceCache::firstPlaying();
    }

    private function caption(?NowPlaying $nowPlaying): string
    {
        $track = $nowPlaying?->track;

        if (!$track?->name) {
            return '<fg=gray>♪ silence ♪</>';
        }

        $name = OutputFormatter::escape($track->name);
        $artist = $track->artist?->name ? ' <fg=gray>—</> '.OutputFormatter::escape($track->artist->name) : '';

        return "♪ <options=bold>{$name}</>{$artist}";
    }
}
