<?php

namespace App\Console\Commands\History;

use App\Domain\Stats\PlayRetention;
use Illuminate\Console\Command;

class PruneHistory extends Command
{
    protected $signature = 'history:prune {--months= : Keep this many months instead of the setting} {--dry-run : Only count the plays that would be deleted}';

    protected $description = 'Delete plays older than the history retention (setting; default keep forever). Tracks are never touched';

    public function handle(): int
    {
        $months = $this->option('months') !== null ? (int) $this->option('months') : PlayRetention::months();

        if ($months === null || $months < 1) {
            $this->info('History is kept forever (no retention set); nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info(PlayRetention::expired($months)." plays older than {$months} months would be deleted (before ".PlayRetention::cutoff($months)->toDateString().').');

            return self::SUCCESS;
        }

        $this->info('Deleted '.PlayRetention::prune($months)." plays older than {$months} months.");

        return self::SUCCESS;
    }
}
