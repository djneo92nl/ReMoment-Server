<?php

namespace App\Console\Commands\Database;

use App\Domain\Database\SqliteImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        $source = SqliteImporter::open($path);
        $target = DB::connection();
        $tables = SqliteImporter::tables($source, $target);

        foreach ($tables as $table) {
            if ($target->table($table)->exists() && !$this->option('force')) {
                $this->error("Table {$table} already has rows: run on a fresh migrate, or pass --force to empty it.");

                return self::FAILURE;
            }
        }

        $result = (new SqliteImporter(fn ($line) => $this->line($line), fn ($line) => $this->warn($line)))
            ->copy($source, $target, $tables, (int) $this->option('chunk'));
        $failed = $result['failed'];

        $this->newLine();
        $failed
            ? $this->warn("Done with {$failed} skipped rows: see the warnings above.")
            : $this->info('Done: every row was copied.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
