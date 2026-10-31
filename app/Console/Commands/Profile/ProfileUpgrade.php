<?php

namespace App\Console\Commands\Profile;

use App\Domain\Database\SqliteImporter;
use App\Domain\Profile\Profile;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Control to Standard (or Full): moves the data from the SQLite file to MariaDB. Backup first (refuses to go on without
 * one), connectivity, migrate the target, import with the db:import-sqlite machinery, verify row counts, then print what
 * to change by hand. It edits no .env and restarts nothing, so the install keeps running on SQLite until the owner
 * switches. Standard to Control is not supported (docs/architecture/profiles.md).
 */
class ProfileUpgrade extends Command
{
    protected $signature = 'profile:upgrade
        {--to-profile=standard : The profile to move to: standard or full}
        {--to=mariadb : The target database connection (config/database.php; DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD)}
        {--database= : The target database name (default: remoment; DB_DATABASE is the SQLite file in Control)}
        {--from= : The SQLite file to copy from (default: the configured SQLite database, copied consistently with VACUUM INTO)}
        {--dry-run : Skip the backup and the writes: check the target connection and report row counts only}
        {--force : Copy into a target that already has rows (they are emptied first)}';

    protected $description = 'Move a Control install from SQLite to MariaDB (backup first); prints the .env changes to make, edits nothing';

    public function handle(): int
    {
        $profile = strtolower((string) $this->option('to-profile'));
        if (!in_array($profile, [Profile::STANDARD, Profile::FULL], true)) {
            $this->error("Upgrade to 'standard' or 'full'. Moving a profile down (Standard to Control) is not supported: the library data would be dropped.");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $name = (string) $this->option('to');

        if ($name === 'mariadb' && $this->option('database')) {
            config(['database.connections.mariadb.database' => (string) $this->option('database')]);
        } elseif ($name === 'mariadb') {
            config(['database.connections.mariadb.database' => 'remoment']);
        }

        // (a) Backup first.
        if ($dry) {
            $this->line('Dry run: no backup, no writes.');
        } else {
            $this->info('1/6 Backup');
            if (Artisan::call('backup:run') !== 0) {
                $this->error(trim(Artisan::output()));
                $this->error('The backup failed, so nothing was changed. Fix that first: the backup is the way back.');

                return self::FAILURE;
            }
            $this->line(trim(Artisan::output()));
        }

        // The source: a consistent snapshot of the live SQLite file, or an explicit file.
        $snapshot = null;
        try {
            [$source, $snapshot] = $this->openSource();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            return $this->run_($name, $source, $profile, $dry);
        } finally {
            if ($snapshot !== null) {
                File::delete($snapshot);
            }
        }
    }

    private function run_(string $name, Connection $source, string $profile, bool $dry): int
    {
        // (b) Connectivity.
        $this->info('2/6 Target database');
        try {
            $target = DB::connection($name);
            $target->getPdo();
        } catch (Throwable $e) {
            $this->error("Cannot reach the target database ('{$name}'): ".mb_substr($e->getMessage(), 0, 300));
            $this->line('Start MariaDB (docker compose --profile db up -d mariadb) and check DB_HOST, DB_PORT, DB_USERNAME and DB_PASSWORD in .env.');

            return self::FAILURE;
        }
        $this->line('Connected to '.$target->getDriverName().' database '.$target->getDatabaseName().'.');

        if ($dry) {
            return $this->report($source, $target);
        }

        // (c) Migrate the target.
        $this->info('3/6 Migrate the target');
        if (Artisan::call('migrate', ['--database' => $name, '--force' => true]) !== 0) {
            $this->error(trim(Artisan::output()));

            return self::FAILURE;
        }

        $tables = SqliteImporter::tables($source, $target);
        $filled = array_values(array_filter($tables, fn ($t) => $target->table($t)->exists()));
        if ($filled !== [] && !$this->option('force')) {
            $this->error('The target already has rows in: '.implode(', ', array_slice($filled, 0, 8)).'. Run against an empty database, or pass --force to empty those tables first.');

            return self::FAILURE;
        }

        // (d) Import.
        $this->info('4/6 Copy the data');
        $result = (new SqliteImporter(fn ($l) => $this->line($l), fn ($l) => $this->warn($l)))->copy($source, $target, $tables);

        // (e) Verify.
        $this->info('5/6 Verify row counts');
        $bad = $this->verify($source, $target);
        if ($bad !== [] || $result['failed'] > 0) {
            $this->error('The copy is not complete: '.($bad !== [] ? 'counts differ in '.implode(', ', $bad).'. ' : '').($result['failed'] > 0 ? "{$result['failed']} rows were skipped. " : '').'Your SQLite install is untouched and still the live one.');

            return self::FAILURE;
        }
        $this->line('Every important table has the same number of rows on both sides.');

        // (f) What to do next.
        $this->info('6/6 Switch over (nothing was changed for you)');
        $this->nextSteps($name, $target, $profile);

        return self::SUCCESS;
    }

    /** @return array{0: Connection, 1: ?string} the source connection and the snapshot file to delete afterwards */
    private function openSource(): array
    {
        if ($from = $this->option('from')) {
            if (!is_file($from)) {
                throw new \RuntimeException("No such file: {$from}");
            }

            return [SqliteImporter::open((string) $from, 'upgrade_source'), null];
        }

        $name = (string) config('database.default');
        $config = config("database.connections.{$name}");
        $live = (string) ($config['database'] ?? '');
        if (($config['driver'] ?? null) !== 'sqlite' || in_array($live, ['', ':memory:'], true) || !is_file($live)) {
            throw new \RuntimeException('The default database is not a SQLite file. Point at one with --from=path (a copy of the Control database).');
        }

        // VACUUM INTO writes a consistent copy while the listeners and workers keep writing.
        $snapshot = sys_get_temp_dir().'/remoment-upgrade-'.bin2hex(random_bytes(4)).'.sqlite';
        DB::connection($name)->statement('VACUUM INTO '.DB::connection($name)->getPdo()->quote($snapshot));
        $this->line("Snapshot of {$live} taken (rows written from now on are not in it: stop the stack before switching).");

        return [SqliteImporter::open($snapshot, 'upgrade_source'), $snapshot];
    }

    private function report(Connection $source, Connection $target): int
    {
        $rows = [];
        foreach ($this->verifyTables($source) as $table) {
            $rows[] = [$table, $source->table($table)->count(), $target->getSchemaBuilder()->hasTable($table) ? $target->table($table)->count() : 'no table yet'];
        }
        $this->table(['Table', 'SQLite rows', 'Target rows'], $rows);
        $this->line('Dry run: nothing was written. Run again without --dry-run to back up, migrate and copy.');

        return self::SUCCESS;
    }

    /** @return list<string> tables whose counts differ */
    private function verify(Connection $source, Connection $target): array
    {
        $bad = [];
        $rows = [];
        foreach ($this->verifyTables($source) as $table) {
            if (!$target->getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $from = $source->table($table)->count();
            $to = $target->table($table)->count();
            $rows[] = [$table, $from, $to, $from === $to ? 'ok' : 'DIFFERENT'];
            $from === $to || $bad[] = $table;
        }
        $this->table(['Table', 'SQLite rows', 'Target rows', ''], $rows);

        return $bad;
    }

    /** @return list<string> the important tables that exist in the source */
    private function verifyTables(Connection $source): array
    {
        return array_values(array_filter(config('profile.upgrade_verify_tables'), fn ($t) => $source->getSchemaBuilder()->hasTable($t)));
    }

    private function nextSteps(string $name, Connection $target, string $profile): void
    {
        $this->newLine();
        $this->line('The data is copied. This command changed no .env and restarted nothing; the install still runs on SQLite. To switch:');
        $this->newLine();
        $this->line('  1. Stop the stack so nothing more is written to SQLite:   docker compose -f docker-compose.prod.yml stop app');
        $this->line('     (anything written since the snapshot is lost; run this command again after stopping if the stack was busy)');
        $this->line('  2. In .env set:');
        $this->line("       PROFILE={$profile}");
        $this->line('       DB_CONNECTION=mariadb');
        $this->line('       DB_HOST=127.0.0.1');
        $this->line('       DB_PORT=3306');
        $this->line('       DB_DATABASE='.$target->getDatabaseName());
        $this->line('       DB_USERNAME=…   DB_PASSWORD=…   (the values you used for this run)');
        $this->line('       COMPOSE_PROFILES=db'.($profile === Profile::FULL ? ',analysis' : '').'   (adds MariaDB; keep your other compose profiles)');
        $this->line('     With Ansible: set profile: '.$profile.' in group_vars and deploy.');
        $this->line('  3. Start it again:   docker compose -f docker-compose.prod.yml up -d');
        $this->line('  4. Check /settings/health and /api/info (features.profile = '.$profile.'), then keep the SQLite file as a backup for a while.');
    }
}
