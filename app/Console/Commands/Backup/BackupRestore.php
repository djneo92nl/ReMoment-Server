<?php

namespace App\Console\Commands\Backup;

use App\Domain\Backup\BackupRestorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BackupRestore extends Command
{
    protected $signature = 'backup:restore
        {archive : A backup archive made by backup:run}
        {--force : Empty tables that already have rows before restoring into them}';

    protected $description = 'Restore a backup archive into the configured, migrated database (run migrate first)';

    public function handle(BackupRestorer $restorer): int
    {
        $archive = (string) $this->argument('archive');

        try {
            $manifest = $restorer->manifest($archive);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Backup of %s, app %s, %s, last migration %s', $manifest['created_at'], $manifest['app_version'], $manifest['database']['driver'], $manifest['database']['latest_migration']));
        if ($manifest['with_secrets'] ?? false) {
            $this->warn('This archive contains credentials.');
        }

        $busy = $restorer->occupied($manifest);
        if ($busy && !$this->option('force')) {
            foreach ($busy as $table => $n) {
                $this->line("  {$table}: {$n} rows");
            }
            $this->error('These tables already have rows. Restore on a fresh `php artisan migrate` (without db:seed), or pass --force to replace them.');

            return self::FAILURE;
        }

        $before = $this->counts(array_keys($manifest['tables']));
        $result = $restorer->restore($archive, $manifest);

        $this->table(['Table', 'Before', 'In backup', 'After'], collect($result['restored'])->map(
            fn ($n, $t) => [$t, $before[$t] ?? 0, $manifest['tables'][$t], $n]
        )->values()->all());
        foreach ($result['skipped'] as $t) {
            $this->warn("Skipped {$t}: the table does not exist here (run migrate).");
        }
        $this->info('Restore finished. Restart the workers (queue:restart) and reconnect Spotify/Last.fm if the backup was made without secrets.');

        return self::SUCCESS;
    }

    private function counts(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            try {
                $out[$t] = DB::table($t)->count();
            } catch (\Throwable) {
            }
        }

        return $out;
    }
}
