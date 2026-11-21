<?php

namespace App\Console\Commands\Players;

use App\Domain\Players\PlayerHousekeeping;
use Illuminate\Console\Command;

class HideStalePlayers extends Command
{
    protected $signature = 'players:hide-stale {--days= : Hide players silent for this many days instead of PLAYERS_HIDE_AFTER_DAYS} {--dry-run : Only list them}';

    protected $description = 'Hide ReMoment Players that have not reported for a while (never deletes; a player shows again when it reports)';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : PlayerHousekeeping::days();

        if ($days < 1) {
            $this->info('Player housekeeping is off (PLAYERS_HIDE_AFTER_DAYS=0); nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $stale = PlayerHousekeeping::stale($days);
            $stale->each(fn ($d) => $this->line("#{$d->id} {$d->device_name} (last seen ".($d->last_seen?->toDateTimeString() ?? 'never').')'));
            $this->info("{$stale->count()} players silent for {$days} days would be hidden.");

            return self::SUCCESS;
        }

        $this->info('Hid '.PlayerHousekeeping::hideStale($days)." players silent for {$days} days.");

        return self::SUCCESS;
    }
}
