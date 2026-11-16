<?php

namespace App\Console\Commands;

use App\Domain\Analysis\StemAudio;
use Illuminate\Console\Command;

class StemsPrune extends Command
{
    protected $signature = 'stems:prune {--dry-run : Count what would go}';

    protected $description = 'Remove kept stem audio (stems/{file id}) of files that no longer have it: removed or changed files (docs/architecture/stems-service.md)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $count = StemAudio::pruneOrphans($dry);
        $this->info(($dry ? 'Would remove ' : 'Removed ')."{$count} stem audio ".($count === 1 ? 'directory' : 'directories').'.');

        return self::SUCCESS;
    }
}
