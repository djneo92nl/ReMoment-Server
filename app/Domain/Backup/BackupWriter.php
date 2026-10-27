<?php

namespace App\Domain\Backup;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Writes one backup archive: manifest.json, data/{table}.jsonl (one JSON row per line, the same format for
 * every driver) and env-safe.txt. Never touches the live tables for long: MariaDB is read in one
 * REPEATABLE READ transaction (a consistent snapshot, nothing locked), SQLite is first copied with
 * VACUUM INTO (SQLite's online backup, safe for a live WAL database) and read from the copy.
 */
final class BackupWriter
{
    /** @var list<string> */
    private array $temp = [];

    public function write(string $dir, bool $withDatabase = true, bool $withSecrets = false): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create the backup directory {$dir}");
        }
        $final = rtrim($dir, '/').'/remoment-backup-'.now()->format('Ymd-His').'.zip';
        $partial = $final.'.partial';

        $old = umask(0077);
        try {
            @unlink($partial);
            $zip = new ZipArchive;
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Cannot create {$partial}");
            }

            $counts = [];
            $tables = [];
            if ($withDatabase) {
                $counts = $this->dumpDatabase($zip, $withSecrets);
                $tables = array_keys($counts);
            }

            $zip->addFromString('manifest.json', json_encode($this->manifest($counts, $withDatabase, $withSecrets), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFromString('env-safe.txt', $this->safeEnv());
            if (!$zip->close()) {
                throw new RuntimeException('Writing the archive failed (disk full?)');
            }
            chmod($partial, 0600);
            rename($partial, $final);
        } catch (\Throwable $e) {
            @unlink($partial);
            throw $e;
        } finally {
            umask($old);
            foreach ($this->temp as $file) {
                @unlink($file);
            }
        }

        return $final;
    }

    /** @return array<string,int> rows per table */
    private function dumpDatabase(ZipArchive $zip, bool $withSecrets): array
    {
        $live = DB::connection();
        $snapshot = $live;
        $driver = $live->getDriverName();

        // VACUUM cannot run inside a transaction (only the test suite wraps one around everything); there the live reads are the snapshot.
        if ($driver === 'sqlite' && $live->transactionLevel() === 0) {
            $copy = tempnam(sys_get_temp_dir(), 'remoment-snap-');
            @unlink($copy);
            $this->temp[] = $copy;
            $live->statement('VACUUM INTO '.$live->getPdo()->quote($copy));
            config(['database.connections.backup_snapshot' => ['driver' => 'sqlite', 'database' => $copy, 'prefix' => '', 'foreign_key_constraints' => false]]);
            DB::purge('backup_snapshot');
            $snapshot = DB::connection('backup_snapshot');
        }

        $counts = [];
        $nested = $snapshot->transactionLevel() > 0;
        $nested || $snapshot->beginTransaction();
        try {
            foreach (Snapshot::tables($snapshot, $withSecrets) as $table) {
                $counts[$table] = $this->dumpTable($zip, $snapshot, $table, $withSecrets);
            }
        } finally {
            $nested || $snapshot->rollBack();
        }

        // The data files are read when the archive closes, so the snapshot copy has to outlive this method (see $temp).
        return $counts;
    }

    private function dumpTable(ZipArchive $zip, Connection $db, string $table, bool $withSecrets): int
    {
        $file = tempnam(sys_get_temp_dir(), 'remoment-tbl-');
        $this->temp[] = $file;
        $out = fopen($file, 'w');
        $count = 0;
        $emit = function ($rows) use ($out, $table, $withSecrets, &$count) {
            foreach ($rows as $row) {
                $row = Snapshot::redact($table, (array) $row, $withSecrets);
                if ($row !== null) {
                    fwrite($out, Snapshot::encode($row)."\n");
                    $count++;
                }
            }
        };

        if (in_array('id', $db->getSchemaBuilder()->getColumnListing($table), true)) {
            $db->table($table)->orderBy('id')->chunkById(1000, $emit);
        } else {
            $emit($db->table($table)->get());
        }
        fclose($out);
        $zip->addFile($file, "data/{$table}.jsonl");

        return $count;
    }

    private function manifest(array $counts, bool $withDatabase, bool $withSecrets): array
    {
        $driver = DB::connection()->getDriverName();

        return [
            'format' => Snapshot::FORMAT,
            'created_at' => now()->toIso8601String(),
            'app_version' => config('app.version'),
            'php_version' => PHP_VERSION,
            'database' => [
                'included' => $withDatabase,
                'driver' => $driver,
                'family' => Snapshot::family($driver),
                'latest_migration' => $withDatabase ? Snapshot::latestMigration() : null,
                'migration_batch' => $withDatabase ? DB::table('migrations')->max('batch') : null,
            ],
            'with_secrets' => $withSecrets,
            'tables' => $counts,
        ];
    }

    private function safeEnv(): string
    {
        $lines = ['# Informational only: restore does not apply this. Secrets (APP_KEY, passwords, API keys) are never written.'];
        foreach ((array) config('backup.safe_env') as $key) {
            $value = env($key);
            if ($value !== null) {
                $lines[] = $key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : $value);
            }
        }

        return implode("\n", $lines)."\n";
    }
}
