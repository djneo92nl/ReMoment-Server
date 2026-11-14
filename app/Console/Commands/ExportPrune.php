<?php

namespace App\Console\Commands;

use App\Domain\Export\ExportRetention;
use Illuminate\Console\Command;

class ExportPrune extends Command
{
    protected $signature = 'export:prune';

    protected $description = 'Remove exports older than EXPORT_KEEP_HOURS and keep the exports/ directory under EXPORT_DIR_MAX_GB (docs/architecture/export.md)';

    public function handle(ExportRetention $retention): int
    {
        $r = $retention->prune();
        $this->info("Expired {$r['expired']}, forgotten {$r['forgotten']}, orphans removed {$r['orphans']}, trimmed for size {$r['trimmed']}; {$r['bytes']} bytes left.");

        return self::SUCCESS;
    }
}
