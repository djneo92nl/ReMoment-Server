<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupStatus;
use App\Domain\System\InstalledVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/remoment-upgrade-test-'.uniqid();
        config(['backup.path' => $this->dir, 'app.version' => '1.0.0']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function facts(array $args = ['--check' => true]): array
    {
        \Illuminate\Support\Facades\Artisan::call('app:upgrade', $args + ['--json' => true]);

        return json_decode(\Illuminate\Support\Facades\Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_up_to_date_exits_zero(): void
    {
        InstalledVersion::record();
        $this->artisan('app:upgrade', ['--check' => true])
            ->expectsOutputToContain('Up to date')
            ->assertExitCode(0);
    }

    public function test_pending_migration_exits_ten_and_is_listed(): void
    {
        $name = DB::table('migrations')->orderByDesc('id')->value('migration');
        DB::table('migrations')->where('migration', $name)->delete();

        $this->artisan('app:upgrade', ['--check' => true])
            ->expectsOutputToContain($name)
            ->assertExitCode(10);
    }

    public function test_downgrade_is_refused(): void
    {
        config(['app.version' => '1.0.0']);
        \App\Models\Setting::set(InstalledVersion::KEY, '1.2.0');

        $this->artisan('app:upgrade', ['--check' => true])
            ->expectsOutputToContain('Downgrade')
            ->assertExitCode(1);
    }

    public function test_unknown_newer_migration_is_refused(): void
    {
        DB::table('migrations')->insert(['migration' => '2099_01_01_000000_from_the_future', 'batch' => 99]);

        $this->artisan('app:upgrade', ['--check' => true])
            ->expectsOutputToContain('2099_01_01_000000_from_the_future')
            ->assertExitCode(1);
    }

    public function test_low_disk_blocks(): void
    {
        config(['upgrade.disk_margin_mb' => 1e12]);
        $facts = $this->facts();

        $this->assertFalse($facts['disk_ok']);
        $this->assertTrue($facts['blocked']);
    }

    public function test_json_keys(): void
    {
        $facts = $this->facts();

        foreach (['version_code', 'version_recorded', 'pending_migrations', 'blocked', 'reasons', 'warnings', 'profile', 'disk_ok'] as $key) {
            $this->assertArrayHasKey($key, $facts);
        }
        $this->assertSame('1.0.0', $facts['version_code']);
        $this->assertNull($facts['version_recorded']);
        $this->assertFalse($facts['blocked']);
    }

    public function test_failing_backup_stops_with_exit_two(): void
    {
        // A backup directory below a plain file cannot be created, so the real writer fails.
        $blocker = sys_get_temp_dir().'/remoment-upgrade-blocker-'.uniqid();
        file_put_contents($blocker, 'x');
        config(['backup.path' => $blocker.'/sub']);

        $this->artisan('app:upgrade', ['--force' => true])
            ->expectsOutputToContain('Cannot create the backup directory')
            ->doesntExpectOutputToContain('Part 2')
            ->assertExitCode(2);
        @unlink($blocker);
    }

    public function test_force_skips_the_prompt_and_writes_a_backup_without_migrating(): void
    {
        $name = DB::table('migrations')->orderByDesc('id')->value('migration');
        DB::table('migrations')->where('migration', $name)->delete();

        $this->artisan('app:upgrade', ['--force' => true])
            ->expectsOutputToContain('Backup written')
            ->expectsOutputToContain('Part 2 (migrate and restart) is not built yet')
            ->assertExitCode(0);

        $this->assertCount(1, BackupStatus::archives($this->dir));
        $this->assertFalse(DB::table('migrations')->where('migration', $name)->exists());
    }

    public function test_prompt_is_asked_without_force_and_no_cancels(): void
    {
        $this->artisan('app:upgrade')
            ->expectsConfirmation('Make a backup now?', 'no')
            ->assertExitCode(1);
        $this->assertSame([], BackupStatus::archives($this->dir));
    }

    public function test_json_without_force_refuses(): void
    {
        $this->artisan('app:upgrade', ['--json' => true])->assertExitCode(1);
        $this->assertSame([], BackupStatus::archives($this->dir));
    }
}
