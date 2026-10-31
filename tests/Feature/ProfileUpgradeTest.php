<?php

namespace Tests\Feature;

use App\Domain\Database\SqliteImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** profile:upgrade between two temp SQLite databases (MariaDB is the real target: the machinery is the same). */
class ProfileUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $sourceFile;

    private string $targetFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/remoment-upgrade-test-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->dir);
        $this->sourceFile = "{$this->dir}/source.sqlite";
        $this->targetFile = "{$this->dir}/target.sqlite";
        touch($this->sourceFile);
        touch($this->targetFile);
        config(['backup.path' => "{$this->dir}/backups", 'backup.keep' => 2]);

        // The source: a migrated Control database with a few rows.
        $source = SqliteImporter::open($this->sourceFile, 'upgrade_test_source');
        Artisan::call('migrate', ['--database' => 'upgrade_test_source', '--force' => true]);
        $now = now()->toDateTimeString();
        foreach ([1, 2, 3] as $i) {
            $source->table('devices')->insert(['device_name' => "Panel {$i}", 'ip_address' => "10.0.0.{$i}", 'created_at' => $now, 'updated_at' => $now]);
        }
        $source->table('plays')->insert(['device_id' => 1, 'played_at' => $now, 'track_name' => 'Song', 'artist_name' => 'Artist', 'created_at' => $now, 'updated_at' => $now]);
        $source->table('settings')->insert(['key' => 'k', 'value' => 'v', 'created_at' => $now, 'updated_at' => $now]);

        // The target: an empty file the command has to migrate itself.
        config(['database.connections.upgrade_test_target' => ['driver' => 'sqlite', 'database' => $this->targetFile, 'prefix' => '', 'foreign_key_constraints' => true]]);
    }

    protected function tearDown(): void
    {
        DB::purge('upgrade_test_source');
        DB::purge('upgrade_test_target');
        DB::purge('upgrade_source');
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function target(): \Illuminate\Database\Connection
    {
        DB::purge('upgrade_test_target');

        return DB::connection('upgrade_test_target');
    }

    public function test_it_backs_up_migrates_copies_verifies_and_prints_the_next_steps(): void
    {
        $this->artisan('profile:upgrade', ['--from' => $this->sourceFile, '--to' => 'upgrade_test_target'])
            ->expectsOutputToContain('Backup written')
            ->expectsOutputToContain('Every important table has the same number of rows')
            ->expectsOutputToContain('PROFILE=standard')
            ->expectsOutputToContain('DB_CONNECTION=mariadb')
            ->assertSuccessful();

        $target = $this->target();
        $this->assertSame(3, $target->table('devices')->count());
        $this->assertSame(1, $target->table('plays')->count());
        $this->assertSame('v', $target->table('settings')->where('key', 'k')->value('value'));
        $this->assertCount(1, glob("{$this->dir}/backups/*"), 'a backup archive was written first');
    }

    public function test_it_edits_no_env_file(): void
    {
        $env = base_path('.env');
        $before = is_file($env) ? md5_file($env) : null;

        $this->artisan('profile:upgrade', ['--from' => $this->sourceFile, '--to' => 'upgrade_test_target', '--to-profile' => 'full'])
            ->expectsOutputToContain('PROFILE=full')->assertSuccessful();

        $this->assertSame($before, is_file($env) ? md5_file($env) : null);
    }

    public function test_it_refuses_to_continue_when_the_backup_fails(): void
    {
        file_put_contents("{$this->dir}/notadir", 'x');
        config(['backup.path' => "{$this->dir}/notadir/backups"]);

        $this->artisan('profile:upgrade', ['--from' => $this->sourceFile, '--to' => 'upgrade_test_target'])
            ->expectsOutputToContain('The backup failed')->assertFailed();

        $this->assertFalse($this->target()->getSchemaBuilder()->hasTable('devices'), 'the target was not even migrated');
    }

    public function test_it_stops_when_the_target_cannot_be_reached(): void
    {
        config(['database.connections.upgrade_test_target.database' => "{$this->dir}/missing-dir/x.sqlite"]);

        $this->artisan('profile:upgrade', ['--from' => $this->sourceFile, '--to' => 'upgrade_test_target', '--dry-run' => true])
            ->expectsOutputToContain('Cannot reach the target database')->assertFailed();
    }

    public function test_a_dry_run_reports_counts_and_writes_nothing(): void
    {
        $this->artisan('profile:upgrade', ['--from' => $this->sourceFile, '--to' => 'upgrade_test_target', '--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing was written')
            ->assertSuccessful();

        $this->assertFalse($this->target()->getSchemaBuilder()->hasTable('devices'));
        $this->assertDirectoryDoesNotExist("{$this->dir}/backups");
    }

    public function test_it_refuses_a_target_that_already_has_rows_unless_forced(): void
    {
        $this->artisan('profile:upgrade', ['--from' => $this->sourceFile, '--to' => 'upgrade_test_target'])->assertSuccessful();

        $this->artisan('profile:upgrade', ['--from' => $this->sourceFile, '--to' => 'upgrade_test_target'])
            ->expectsOutputToContain('already has rows')->assertFailed();

        $this->artisan('profile:upgrade', ['--from' => $this->sourceFile, '--to' => 'upgrade_test_target', '--force' => true])->assertSuccessful();
        $this->assertSame(3, $this->target()->table('devices')->count());
    }

    public function test_downgrading_is_not_supported(): void
    {
        $this->artisan('profile:upgrade', ['--to-profile' => 'control'])
            ->expectsOutputToContain('not supported')->assertFailed();
    }

    public function test_db_import_sqlite_still_works_through_the_shared_importer(): void
    {
        // The command refuses a SQLite target, as before.
        $this->artisan('db:import-sqlite', ['path' => $this->sourceFile])->expectsOutputToContain('SQLite already')->assertFailed();
    }

    public function test_the_source_can_be_the_live_sqlite_file_through_a_snapshot(): void
    {
        $original = config('database.default');
        config(['database.connections.control_live' => ['driver' => 'sqlite', 'database' => $this->sourceFile, 'prefix' => '', 'foreign_key_constraints' => false], 'database.default' => 'control_live']);

        try {
            $this->artisan('profile:upgrade', ['--to' => 'upgrade_test_target'])
                ->expectsOutputToContain('Snapshot of')->assertSuccessful();
        } finally {
            config(['database.default' => $original]);
            DB::purge('control_live');
        }

        $this->assertSame(3, $this->target()->table('devices')->count());
    }
}
