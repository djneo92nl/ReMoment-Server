<?php

namespace App\Console\Commands\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportSqlite extends Command
{
    /** Throwaway or derived data: it is rebuilt by the app, so it is not worth carrying over. */
    public const SKIP = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'lost_and_found'];

    protected $signature = 'db:import-sqlite
        {path : The SQLite database file to copy from}
        {--chunk=1000 : Rows per insert}
        {--force : Empty a table that already has rows before copying into it}';

    protected $description = 'Copy the data of a SQLite database file into the configured database (run migrate first)';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (!is_file($path)) {
            $this->error('No such file: '.$path);

            return self::FAILURE;
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->error('The configured database is SQLite already: set DB_CONNECTION=mariadb first.');

            return self::FAILURE;
        }

        config(['database.connections.sqlite_import' => [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        $source = DB::connection('sqlite_import');
        $target = DB::connection();

        $tables = $source->table('sqlite_master')->where('type', 'table')->where('name', 'not like', 'sqlite_%')
            ->orderBy('name')->pluck('name')->reject(fn ($t) => in_array($t, self::SKIP, true))
            ->filter(fn ($t) => Schema::hasTable($t))->values();

        foreach ($tables as $table) {
            if ($target->table($table)->exists() && !$this->option('force')) {
                $this->error("Table {$table} already has rows: run on a fresh migrate, or pass --force to empty it.");

                return self::FAILURE;
            }
        }

        $target->statement('SET FOREIGN_KEY_CHECKS=0');
        $report = [];
        $failed = 0;

        foreach ($tables as $table) {
            $target->table($table)->truncate();
            $columns = array_values(array_intersect(Schema::getColumnListing($table), $source->getSchemaBuilder()->getColumnListing($table)));
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
                }
                $buffer = [];
            };

            foreach ($source->table($table)->select($columns)->cursor() as $row) {
                $buffer[] = (array) $row;
                if (count($buffer) >= (int) $this->option('chunk')) {
                    $flush();
                }
            }
            $flush();

            $total = $source->table($table)->count();
            $failed += $skipped;
            $report[] = [$table, $total, $target->table($table)->count(), $skipped];
            $this->line(sprintf('%-22s %8d -> %8d%s', $table, $total, $target->table($table)->count(), $skipped ? "  ({$skipped} skipped)" : ''));
        }

        $target->statement('SET FOREIGN_KEY_CHECKS=1');
        $this->newLine();
        $failed
            ? $this->warn("Done with {$failed} skipped rows: see the warnings above.")
            : $this->info('Done: every row was copied.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
