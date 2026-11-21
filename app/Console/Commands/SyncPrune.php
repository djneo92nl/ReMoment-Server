<?php

namespace App\Console\Commands;

use App\Domain\Sync\SyncArtifacts;
use Illuminate\Console\Command;

class SyncPrune extends Command
{
    protected $signature = 'sync:prune';

    protected $description = 'Keep the cache of prepared sync files under SYNC_CACHE_GB, least recently used first (docs/architecture/offline-sync.md)';

    public function handle(): int
    {
        $r = SyncArtifacts::prune();
        $this->info("Removed {$r['removed']} artifacts ({$r['bytes']} bytes), {$r['stale']} stale rows, {$r['orphans']} orphan files; cache holds ".SyncArtifacts::size().' bytes.');

        return self::SUCCESS;
    }
}
