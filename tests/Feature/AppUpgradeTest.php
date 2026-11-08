<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupStatus;
use App\Domain\Health\Heartbeat;
use App\Domain\System\InstalledVersion;
use App\Domain\System\UpgradeHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
        config(['backup.path' => $this->dir, 'app.version' => '1.0.0', 'upgrade.after_migrate' => []]);
    }

    protected function tearDown(): void
    {
        $this->runTearDownExtras();
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

    private string $migDir;

    private function beat(): void
    {
        Heartbeat::beat(Heartbeat::SCHEDULER);
        Heartbeat::beat(Heartbeat::QUEUE);
    }

    /** A migration from an extra path stands in for a pending one of a new release. */
    private function extraMigration(string $body): void
    {
        $this->migDir = sys_get_temp_dir().'/remoment-upgrade-mig-'.uniqid();
        mkdir($this->migDir);
        file_put_contents($this->migDir.'/2099_01_01_000000_upgrade_probe.php', "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public function up(): void { {$body} } };\n");
        app('migrator')->path($this->migDir);
    }

    private function run_(array $args = []): array
    {
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput;
        $code = Artisan::call('app:upgrade', $args + ['--force' => true, '--json' => true, '--health-timeout' => 0], $buffer);
        $json = json_decode($buffer->fetch(), true, 512, JSON_THROW_ON_ERROR);

        return [$code, $json];
    }

    protected function runTearDownExtras(): void
    {
        if (app()->isDownForMaintenance()) {
            Artisan::call('up');
        }
    }

    public function test_successful_upgrade_end_to_end(): void
    {
        $this->extraMigration("Schema::create('upgrade_probe', fn (Blueprint \$t) => \$t->id());");
        InstalledVersion::record();
        config(['app.version' => '1.1.0']);
        $this->beat();

        [$code, $json] = $this->run_();
        $this->runTearDownExtras();

        $this->assertSame(0, $code);
        $this->assertSame('ok', $json['exit_reason']);
        $this->assertSame(['2099_01_01_000000_upgrade_probe'], $json['migrations_run']);
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('upgrade_probe'));
        $this->assertSame('1.1.0', InstalledVersion::recorded());
        $this->assertFalse(app()->isDownForMaintenance());
        $names = array_column($json['steps'], 'name');
        foreach (['backup', 'maintenance_on', 'migrate', 'queue:restart', 'record_version', 'maintenance_off', 'health'] as $n) {
            $this->assertContains($n, $names);
        }
        $this->assertSame('1.0.0', $json['rollback']['previous_version']);
        $this->assertStringContainsString('backup:restore', implode("\n", $json['rollback']['commands']));
        $this->assertStringContainsString('forward-only', $json['rollback']['note']);
        $this->assertCount(1, BackupStatus::archives($this->dir));
        $this->assertSame('ok', UpgradeHistory::latest()['result']);
        @unlink($this->migDir.'/2099_01_01_000000_upgrade_probe.php');
        @rmdir($this->migDir);
    }

    public function test_failing_migration_leaves_app_down_and_exits_three(): void
    {
        $this->extraMigration("throw new \\RuntimeException('boom');");
        InstalledVersion::record();
        config(['app.version' => '1.1.0']);

        [$code, $json] = $this->run_();
        $down = app()->isDownForMaintenance();
        $this->runTearDownExtras();

        $this->assertSame(3, $code);
        $this->assertTrue($down);
        $this->assertSame('migration_failed', $json['exit_reason']);
        $this->assertSame('1.0.0', InstalledVersion::recorded());
        $this->assertNotContains('queue:restart', array_column($json['steps'], 'name'));
        $this->assertNotNull($json['rollback']['backup']);
        $this->assertSame('migration_failed', UpgradeHistory::latest()['result']);
        @unlink($this->migDir.'/2099_01_01_000000_upgrade_probe.php');
        @rmdir($this->migDir);
    }

    public function test_no_maintenance_skips_maintenance_mode(): void
    {
        $this->beat();
        [$code, $json] = $this->run_(['--no-maintenance' => true]);

        $this->assertSame(0, $code);
        $this->assertNotContains('maintenance_off', array_column($json['steps'], 'name'));
        $this->assertSame('skipped', collect($json['steps'])->firstWhere('name', 'maintenance_on')['result']);
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_health_timeout_exits_four_and_rolls_nothing_back(): void
    {
        // No heartbeats exist, so the check fails at once with a zero timeout.
        [$code, $json] = $this->run_();

        $this->assertSame(4, $code);
        $this->assertSame('health_failed', $json['exit_reason']);
        $this->assertFalse($json['health']['ok']);
        $this->assertNotEmpty($json['health']['failures']);
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertSame('health_failed', UpgradeHistory::latest()['result']);
    }

    public function test_critical_alert_fails_health(): void
    {
        $this->beat();
        \App\Domain\Alerts\Alerts::raise('test.kind', 'k', 'critical', 'Broken', 'It broke');
        [$code, $json] = $this->run_();

        $this->assertSame(4, $code);
        $this->assertStringContainsString('Broken', implode(' ', $json['health']['failures']));
    }

    public function test_history_keeps_ten_entries_newest_first(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            UpgradeHistory::add("1.0.$i", '1.1.0', 'ok', '/b/'.$i);
        }
        $all = UpgradeHistory::all();

        $this->assertCount(10, $all);
        $this->assertSame('1.0.12', $all[0]['from']);
        $this->assertSame('1.0.3', $all[9]['from']);
    }

    public function test_json_has_the_new_keys(): void
    {
        $this->beat();
        [, $json] = $this->run_();

        foreach (['steps', 'migrations_run', 'exit_reason', 'rollback', 'backup'] as $key) {
            $this->assertArrayHasKey($key, $json);
        }
        $this->assertSame(['name', 'result', 'seconds'], array_keys($json['steps'][0]));
    }

    public function test_health_card_shows_version_and_last_upgrade(): void
    {
        UpgradeHistory::add('1.0.0', '1.1.0', 'ok', '/b/x');
        \Livewire\Livewire::test(\App\Livewire\SystemHealth::class)
            ->assertSee('Last upgrade')
            ->assertSee('1.0.0');
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
