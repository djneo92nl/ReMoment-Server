<?php

namespace App\Domain\System;

use App\Domain\Health\Heartbeat;
use App\Domain\Profile\Profile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only pre-flight of `app:upgrade`: version, database, migrations, disk space for a backup, heartbeats, profile.
 * Changes nothing. Result keys are the stable JSON of `app:upgrade --json`.
 */
final class UpgradeCheck
{
    private const HEARTBEAT_STALE_SECONDS = 180;

    /** @return array<string,mixed> */
    public static function run(): array
    {
        $reasons = [];
        $warnings = [];
        $code = InstalledVersion::current();
        $recorded = InstalledVersion::recorded();
        $pending = [];
        $dbOk = true;

        try {
            DB::connection()->getPdo();
            DB::connection()->getSchemaBuilder()->hasTable('migrations');
        } catch (Throwable $e) {
            $dbOk = false;
            $reasons[] = 'Cannot connect to the database: '.$e->getMessage();
        }

        if ($dbOk) {
            if ($recorded !== null && version_compare($code, $recorded, '<')) {
                $reasons[] = "Downgrade: the code is version {$code} but the database was last upgraded to {$recorded}. Install the newer version again, or restore a backup made before.";
            }

            $files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
            sort($files);
            $ran = [];
            try {
                $ran = DB::getSchemaBuilder()->hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
            } catch (Throwable $e) {
                $reasons[] = 'Cannot read the migrations table: '.$e->getMessage();
            }
            $unknown = array_values(array_diff($ran, $files));
            sort($unknown);
            if ($unknown) {
                $reasons[] = 'The database has migrations this code does not know (a newer release ran here): '.implode(', ', array_slice($unknown, 0, 5)).(count($unknown) > 5 ? ', …' : '').'. Update the code, do not upgrade.';
            }
            $pending = array_values(array_diff($files, $ran));
        }

        $diskOk = true;
        $dir = (string) config('backup.path');
        $needed = null;
        $free = null;
        if ($dbOk) {
            $needed = self::databaseBytes() + (int) config('upgrade.disk_margin_mb', 100) * 1048576;
            $probe = $dir;
            while ($probe !== '' && !is_dir($probe) && dirname($probe) !== $probe) {
                $probe = dirname($probe);
            }
            $free = $probe !== '' && is_dir($probe) ? @disk_free_space($probe) : false;
            if ($free === false) {
                $diskOk = false;
                $reasons[] = "Cannot read the free disk space for the backup directory {$dir}.";
            } elseif ($free < $needed) {
                $diskOk = false;
                $reasons[] = sprintf('Not enough free disk space for a backup in %s: %s free, about %s needed.', $dir, self::mb($free), self::mb($needed));
            }
            if ($probe !== '' && !is_writable($probe)) {
                $diskOk = false;
                $reasons[] = "The backup directory {$dir} is not writable.";
            }
        }

        foreach ([Heartbeat::SCHEDULER => 'scheduler', Heartbeat::QUEUE => 'queue worker'] as $key => $label) {
            try {
                $last = Heartbeat::last($key);
            } catch (Throwable) {
                $last = null;
            }
            if ($last === null || $last->diffInSeconds(now(), true) >= self::HEARTBEAT_STALE_SECONDS) {
                $warnings[] = "The {$label} has no recent heartbeat (as on /settings/health): it may not be running.";
            }
        }

        return [
            'version_code' => $code,
            'version_recorded' => $recorded,
            'pending_migrations' => $pending,
            'blocked' => $reasons !== [],
            'reasons' => $reasons,
            'warnings' => $warnings,
            'profile' => Profile::current(),
            'disk_ok' => $diskOk,
        ];
    }

    private static function databaseBytes(): int
    {
        try {
            $db = DB::connection();
            if ($db->getDriverName() === 'sqlite') {
                $file = (string) $db->getDatabaseName();

                return is_file($file) ? (int) filesize($file) : 0;
            }
            $row = $db->selectOne('SELECT COALESCE(SUM(data_length + index_length), 0) AS b FROM information_schema.tables WHERE table_schema = ?', [$db->getDatabaseName()]);

            return (int) ($row->b ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private static function mb(int|float $bytes): string
    {
        return round($bytes / 1048576, 1).' MB';
    }
}
