<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupWriter;
use App\Domain\Profile\Profile;
use App\Domain\System\InstalledVersion;
use App\Domain\System\UpgradeCheck;
use App\Domain\System\UpgradeHealth;
use App\Domain\System\UpgradeHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Upgrade (9a): pre-flight, mandatory backup, maintenance mode, migrate, caches, worker restart, version record,
 * health check, rollback instructions, history. Exit codes: 0 ok (check: nothing pending), 10 (check: an upgrade is
 * available), 1 blocked or cancelled, 2 backup failed, 3 migration failed (app left down), 4 health check failed.
 */
class AppUpgrade extends Command
{
    public const EXIT_AVAILABLE = 10;

    public const EXIT_BACKUP_FAILED = 2;

    public const EXIT_MIGRATION_FAILED = 3;

    public const EXIT_HEALTH_FAILED = 4;

    protected $signature = 'app:upgrade
        {--check : Only check: exit 0 when nothing is pending, 10 when an upgrade is available, 1 when something blocks it}
        {--json : Print the check as JSON (never prompts; a real run then needs --force)}
        {--force : Do not ask for confirmation before the backup}
        {--no-maintenance : Do not put the app in maintenance mode while migrating}
        {--health-timeout=120 : Seconds to wait for a healthy app after migrating}';

    protected $description = 'Check, back up, migrate, restart workers, check health and print how to roll back';

    public function handle(BackupWriter $writer): int
    {
        $facts = UpgradeCheck::run();
        $json = (bool) $this->option('json');
        $checkOnly = (bool) $this->option('check');

        if ($checkOnly) {
            $json ? $this->line(json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) : $this->report($facts);

            return $facts['blocked'] ? self::FAILURE : ($facts['pending_migrations'] ? self::EXIT_AVAILABLE : self::SUCCESS);
        }

        if (!$json) {
            $this->report($facts);
        }
        if ($facts['blocked']) {
            $json && $this->line(json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        if (!$this->option('force')) {
            if ($json || !$this->input->isInteractive()) {
                $this->error('Refusing to continue without confirmation: pass --force when running non-interactively or with --json.');

                return self::FAILURE;
            }
            if (!$this->confirm('Make a backup now?', true)) {
                $this->line('Cancelled; nothing was changed.');

                return self::FAILURE;
            }
        }

        $this->json = $json;
        $this->out = $facts + ['steps' => [], 'migrations_run' => [], 'exit_reason' => null, 'backup' => null, 'rollback' => null, 'health' => null];

        $t = microtime(true);
        try {
            $dir = (string) config('backup.path');
            $file = $writer->write($dir, true, false);
            $size = filesize($file);
        } catch (Throwable $e) {
            $this->step('backup', 'failed', $t);
            $this->problem('Backup failed: '.$e->getMessage());
            $this->problem('Nothing was changed. Fix that first: the backup is the way back.');

            return $this->finish(self::EXIT_BACKUP_FAILED, 'backup_failed');
        }
        $this->step('backup', 'ok', $t);
        $this->out['backup'] = $file;
        $this->say(sprintf('Backup written: %s (%s)', $file, $size >= 1048576 ? round($size / 1048576, 1).' MB' : max(1, (int) round($size / 1024)).' KB'));

        $from = $facts['version_recorded'];
        $to = InstalledVersion::current();
        $this->out['rollback'] = $this->rollback($from, $file);

        // Maintenance mode protects a half-migrated database from requests; only the upgrade that started it ends it.
        $maintenance = !$this->option('no-maintenance') && !app()->isDownForMaintenance();
        if ($maintenance) {
            $t = microtime(true);
            Artisan::call('down', ['--retry' => 60]);
            $this->step('maintenance_on', 'ok', $t);
            $this->say('Maintenance mode on.');
        } else {
            $this->step('maintenance_on', 'skipped', microtime(true));
        }

        $t = microtime(true);
        $before = $this->ranMigrations();
        try {
            Artisan::call('migrate', ['--force' => true]);
            $this->out['migrations_run'] = array_values(array_diff($this->ranMigrations(), $before));
            $this->step('migrate', 'ok', $t);
            $this->say('Migrations run: '.($this->out['migrations_run'] ? implode(', ', $this->out['migrations_run']) : 'none'));
        } catch (Throwable $e) {
            $this->out['migrations_run'] = array_values(array_diff($this->ranMigrations(), $before));
            $this->step('migrate', 'failed', $t);
            $this->problem('Migration failed: '.$e->getMessage());
            if ($maintenance) {
                $this->problem('The app stays in maintenance mode: the database may be half-migrated. Restore the backup (below), then bring the app up with `php artisan up`.');
            }
            $this->recordHistory($from, $to, 'migration_failed', $file);

            return $this->finish(self::EXIT_MIGRATION_FAILED, 'migration_failed');
        }

        $problems = [];
        foreach ((array) config('upgrade.after_migrate', []) as $cmd) {
            $t = microtime(true);
            try {
                Artisan::call($cmd);
                $this->step($cmd, 'ok', $t);
            } catch (Throwable $e) {
                $this->step($cmd, 'failed', $t);
                $problems[] = "{$cmd} failed: ".$e->getMessage();
            }
        }
        $t = microtime(true);
        try {
            Artisan::call('queue:restart');
            $this->step('queue:restart', 'ok', $t);
            $this->say('Queue workers asked to restart (they finish the job they are on).');
            InstalledVersion::record();
            $this->step('record_version', 'ok', $t);
        } catch (Throwable $e) {
            $this->step('queue:restart', 'failed', $t);
            $problems[] = 'Restarting workers or recording the version failed: '.$e->getMessage();
        }

        if ($maintenance) {
            $t = microtime(true);
            Artisan::call('up');
            $this->step('maintenance_off', 'ok', $t);
            $this->say('Maintenance mode off.');
        }

        $t = microtime(true);
        $this->say('Health check (up to '.(int) $this->option('health-timeout').' s)...');
        $health = UpgradeHealth::wait(max(0, (int) $this->option('health-timeout')), (int) config('upgrade.health_interval', 5));
        $this->step('health', $health['ok'] ? 'ok' : 'failed', $t);
        $problems = array_merge($problems, $health['failures']);
        $this->out['health'] = ['ok' => $problems === [], 'failures' => $problems];

        if ($problems !== []) {
            foreach ($problems as $p) {
                $this->problem('Health check: '.$p);
            }
            $this->problem('Nothing was rolled back. Look at /settings/health; to go back use the instructions below.');
            $this->recordHistory($from, $to, 'health_failed', $file);

            return $this->finish(self::EXIT_HEALTH_FAILED, 'health_failed');
        }

        $this->say('Upgrade finished and healthy.');
        $this->recordHistory($from, $to, 'ok', $file);

        return $this->finish(self::SUCCESS, 'ok');
    }

    private bool $json = false;

    /** @var array<string,mixed> */
    private array $out = [];

    private function say(string $line): void
    {
        $this->json || $this->line($line);
    }

    private function problem(string $line): void
    {
        $this->json || $this->error($line);
        $this->out['problems'][] = $line;
    }

    private function step(string $name, string $result, float $since): void
    {
        $this->out['steps'][] = ['name' => $name, 'result' => $result, 'seconds' => round(microtime(true) - $since, 2)];
    }

    /** @return list<string> */
    private function ranMigrations(): array
    {
        return DB::table('migrations')->pluck('migration')->all();
    }

    private function recordHistory(?string $from, string $to, string $result, string $backup): void
    {
        try {
            UpgradeHistory::add($from, $to, $result, $backup);
        } catch (Throwable) {
        }
    }

    /** @return array{previous_version: ?string, backup: string, commands: list<string>, note: string} */
    private function rollback(?string $previous, string $backup): array
    {
        $label = $previous ?? 'the previously installed version';

        return [
            'previous_version' => $previous,
            'backup' => $backup,
            'commands' => [
                "Install {$label} of the code or images (for example: git checkout <tag of that version>, rebuild or pull the images)",
                'php artisan backup:restore '.escapeshellarg($backup).' --force',
                'php artisan up',
            ],
            'note' => 'Migrations are forward-only: there is no migrate:rollback. The backup is the way back; anything saved after it is lost.',
        ];
    }

    private function finish(int $code, string $reason): int
    {
        $this->out['exit_reason'] = $reason;
        $this->out['exit_code'] = $code;
        if ($this->out['rollback']) {
            $r = $this->out['rollback'];
            $this->say('');
            $this->say('To go back (previous version: '.($r['previous_version'] ?? 'unknown').', backup: '.$r['backup'].'):');
            foreach ($r['commands'] as $i => $c) {
                $this->say('  '.($i + 1).'. '.$c);
            }
            $this->say($r['note']);
        }
        if ($this->json) {
            $this->line(json_encode($this->out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $code;
    }

    /** @param array<string,mixed> $f */
    private function report(array $f): void
    {
        $this->line('Code version:      '.$f['version_code']);
        $this->line('Recorded version:  '.($f['version_recorded'] ?? 'none recorded'));
        $this->line('Profile:           '.Profile::label($f['profile']).' ('.$f['profile'].')');
        $this->line('Backup disk:       '.($f['disk_ok'] ? 'ok' : 'NOT ok'));
        if ($f['pending_migrations']) {
            $this->line('Pending migrations ('.count($f['pending_migrations']).'):');
            foreach ($f['pending_migrations'] as $name) {
                $this->line('  - '.$name);
            }
        } else {
            $this->line('Pending migrations: none');
        }
        foreach ($f['warnings'] as $w) {
            $this->warn('Warning: '.$w);
        }
        foreach ($f['reasons'] as $r) {
            $this->error('Blocked: '.$r);
        }
        if (!$f['blocked']) {
            $this->info($f['pending_migrations'] ? 'An upgrade is available.' : 'Up to date.');
        }
    }
}
