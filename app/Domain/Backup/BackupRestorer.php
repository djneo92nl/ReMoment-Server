<?php

namespace App\Domain\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/** Loads a backup archive into the migrated, configured database. */
final class BackupRestorer
{
    /** Reads and validates the manifest; throws on anything that must not be restored. */
    public function manifest(string $archive): array
    {
        $zip = $this->open($archive);
        $json = $zip->getFromName('manifest.json');
        $zip->close();
        $manifest = $json ? json_decode($json, true) : null;
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== Snapshot::FORMAT) {
            throw new RuntimeException('Not a ReMoment backup (manifest.json missing or of another format).');
        }
        if (!($manifest['database']['included'] ?? false)) {
            throw new RuntimeException('This backup was made with --no-db: it holds no database.');
        }

        $current = DB::connection()->getDriverName();
        if (Snapshot::family($manifest['database']['driver']) !== Snapshot::family($current)) {
            throw new RuntimeException("The backup is from {$manifest['database']['driver']} but this install uses {$current}. For SQLite to MariaDB use db:import-sqlite.");
        }

        $archived = $manifest['database']['latest_migration'] ?? null;
        $mine = Snapshot::latestMigrationFile();
        if ($archived && $mine && strcmp($archived, $mine) > 0) {
            throw new RuntimeException("The backup is from a newer version (migration {$archived}); this app only knows up to {$mine}. Update the app first.");
        }

        return $manifest;
    }

    /** Tables of the archive that exist in the target and hold rows. @return array<string,int> */
    public function occupied(array $manifest): array
    {
        $busy = [];
        foreach (array_keys($manifest['tables']) as $table) {
            if (Schema::hasTable($table) && ($n = DB::table($table)->count()) > 0) {
                $busy[$table] = $n;
            }
        }

        return $busy;
    }

    /** @return array{restored: array<string,int>, skipped: list<string>} */
    public function restore(string $archive, array $manifest): array
    {
        $zip = $this->open($archive);
        $db = DB::connection();
        $driver = $db->getDriverName();
        $restored = [];
        $skipped = [];

        $driver === 'sqlite' ? $db->statement('PRAGMA foreign_keys = OFF') : $db->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (array_keys($manifest['tables']) as $table) {
                if (!Schema::hasTable($table)) {
                    $skipped[] = $table;

                    continue;
                }
                $restored[$table] = $this->restoreTable($zip, $table);
            }
        } finally {
            $driver === 'sqlite' ? $db->statement('PRAGMA foreign_keys = ON') : $db->statement('SET FOREIGN_KEY_CHECKS=1');
            $zip->close();
        }

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    private function restoreTable(ZipArchive $zip, string $table): int
    {
        DB::table($table)->truncate();
        $stream = $zip->getStream("data/{$table}.jsonl");
        if (!$stream) {
            return 0;
        }

        $known = array_flip(Schema::getColumnListing($table));
        $buffer = [];
        $count = 0;
        $size = null;
        $flush = function () use (&$buffer, $table) {
            if ($buffer) {
                DB::table($table)->insert($buffer);
                $buffer = [];
            }
        };

        while (($line = fgets($stream)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $row = array_intersect_key(Snapshot::decode($line), $known);
            // SQLite allows 999 bound values per statement on older builds.
            $size ??= max(1, intdiv(900, max(1, count($row))));
            $buffer[] = $row;
            $count++;
            if (count($buffer) >= $size) {
                $flush();
            }
        }
        fclose($stream);
        $flush();

        return $count;
    }

    private function open(string $archive): ZipArchive
    {
        $zip = new ZipArchive;
        if (!is_file($archive) || $zip->open($archive) !== true) {
            throw new RuntimeException("Cannot open the archive {$archive}");
        }

        return $zip;
    }
}
