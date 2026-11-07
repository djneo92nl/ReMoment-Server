<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupWriter;
use App\Domain\Profile\Profile;
use App\Domain\System\UpgradeCheck;
use Illuminate\Console\Command;
use Throwable;

/**
 * Upgrade, part 1 of 9a: the read-only pre-flight and the mandatory backup. It migrates and restarts nothing yet.
 * Exit codes: 0 ok (check: nothing pending), 10 (check: an upgrade is available), 1 blocked or cancelled, 2 backup failed.
 */
class AppUpgrade extends Command
{
    public const EXIT_AVAILABLE = 10;

    public const EXIT_BACKUP_FAILED = 2;

    protected $signature = 'app:upgrade
        {--check : Only check: exit 0 when nothing is pending, 10 when an upgrade is available, 1 when something blocks it}
        {--json : Print the check as JSON (never prompts; a real run then needs --force)}
        {--force : Do not ask for confirmation before the backup}';

    protected $description = 'Check what an upgrade would do and make the backup first (migrating and restarting are not built yet)';

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

        try {
            $dir = (string) config('backup.path');
            $file = $writer->write($dir, true, false);
            $size = filesize($file);
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            $this->error('Nothing was changed. Fix that first: the backup is the way back.');

            return self::EXIT_BACKUP_FAILED;
        }

        $this->info(sprintf('Backup written: %s (%s)', $file, $size >= 1048576 ? round($size / 1048576, 1).' MB' : max(1, (int) round($size / 1024)).' KB'));
        $this->line('Part 2 (migrate and restart) is not built yet; nothing was migrated.');

        return self::SUCCESS;
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
