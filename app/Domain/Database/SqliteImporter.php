<?php

namespace App\Domain\Database;

use App\Console\Commands\Database\ImportSqlite;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copies the data of a SQLite file into another database connection (every table both sides have, the columns they
 * share). The machinery behind `db:import-sqlite` and `profile:upgrade`; the target is MariaDB in production, and
 * any connection in tests. Rows the target's strict mode rejects are skipped and reported; a string over 255
 * characters (SQLite never enforced the length) is shortened and the row kept.
 */
final class SqliteImporter
{
    /** @param callable(string):void $say  progress lines  @param callable(string):void $warn  per-row warnings */
    public function __construct(private $say = null, private $warn = null) {}

    /** The tables that would be copied: those of the source the target has, minus the throwaway ones. @return list<string> */
    public static function tables(Connection $source, Connection $target): array
    {
        $schema = $target->getSchemaBuilder();

        return $source->table('sqlite_master')->where('type', 'table')->where('name', 'not like', 'sqlite_%')
            ->orderBy('name')->pluck('name')->reject(fn ($t) => in_array($t, ImportSqlite::SKIP, true))
            ->filter(fn ($t) => $schema->hasTable($t))->values()->all();
    }

    /** Open a SQLite file as a connection (foreign keys off: rows are copied table by table). */
    public static function open(string $path, string $name = 'sqlite_import'): Connection
    {
        config(["database.connections.{$name}" => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge($name);

        return DB::connection($name);
    }

    /**
     * @param  list<string>  $tables
     * @return array{report: list<array{0: string, 1: int, 2: int, 3: int}>, failed: int} per table: name, source rows, target rows, skipped
     */
    public function copy(Connection $source, Connection $target, array $tables, int $chunk = 1000): array
    {
        $this->setForeignKeys($target, false);
        $report = [];
        $failed = 0;

        try {
            foreach ($tables as $table) {
                $target->table($table)->truncate();
                $columns = array_values(array_intersect($target->getSchemaBuilder()->getColumnListing($table), $source->getSchemaBuilder()->getColumnListing($table)));
                $copied = 0;
                $skipped = 0;
                $buffer = [];

                $flush = function () use (&$buffer, &$copied, &$skipped, $target, $table) {
                    if (!$buffer) {
                        return;
                    }
                    try {
                        $target->table($table)->insert($buffer);
                        $copied += count($buffer);
                    } catch (Throwable) {
                        foreach ($buffer as $row) {
                            $this->insertOne($target, $table, $row, $copied, $skipped);
                        }
                    }
                    $buffer = [];
                };

                foreach ($source->table($table)->select($columns)->cursor() as $row) {
                    $buffer[] = (array) $row;
                    if (count($buffer) >= $chunk) {
                        $flush();
                    }
                }
                $flush();

                $total = $source->table($table)->count();
                $failed += $skipped;
                $report[] = [$table, $total, $target->table($table)->count(), $skipped];
                $this->say(sprintf('%-22s %8d -> %8d%s', $table, $total, $target->table($table)->count(), $skipped ? "  ({$skipped} skipped)" : ''));
            }
        } finally {
            $this->setForeignKeys($target, true);
        }

        return ['report' => $report, 'failed' => $failed];
    }

    private function insertOne(Connection $target, string $table, array $row, int &$copied, int &$skipped): void
    {
        try {
            $target->table($table)->insert($row);
            $copied++;
        } catch (Throwable $e) {
            // SQLite never enforced column lengths (a credits list stored as an artist name):
            // keep the row, shortened, rather than orphaning what points at it.
            $short = array_map(fn ($v) => is_string($v) && mb_strlen($v) > 255 ? mb_substr($v, 0, 255) : $v, $row);
            try {
                if ($short === $row) {
                    throw $e;
                }
                $target->table($table)->insert($short);
                $copied++;
                $this->warn("  {$table}: id ".($row['id'] ?? '?').' had a value over 255 characters, shortened');
            } catch (Throwable $e) {
                $skipped++;
                $this->warn("  {$table}: skipped a row (".mb_substr($e->getMessage(), 0, 160).')');
            }
        }
    }

    private function setForeignKeys(Connection $target, bool $on): void
    {
        $target->getDriverName() === 'sqlite'
            ? $target->statement('PRAGMA foreign_keys='.($on ? 'ON' : 'OFF'))
            : $target->statement('SET FOREIGN_KEY_CHECKS='.($on ? '1' : '0'));
    }

    private function say(string $line): void
    {
        ($this->say)($line);
    }

    private function warn(string $line): void
    {
        ($this->warn)($line);
    }
}
