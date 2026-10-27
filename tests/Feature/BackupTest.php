<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupStatus;
use App\Livewire\SystemHealth;
use App\Models\Device;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/remoment-backup-test-'.uniqid();
        config(['backup.path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function run_(array $options = []): string
    {
        $code = \Illuminate\Support\Facades\Artisan::call('backup:run', $options);
        $this->assertSame(0, $code, \Illuminate\Support\Facades\Artisan::output());
        $files = BackupStatus::archives($options['--path'] ?? $this->dir);

        return end($files);
    }

    private function entry(string $archive, string $name): ?string
    {
        $zip = new ZipArchive;
        $zip->open($archive);
        $content = $zip->getFromName($name);
        $zip->close();

        return $content === false ? null : $content;
    }

    public function test_run_writes_a_private_archive_with_manifest_and_dump(): void
    {
        Device::factory()->create(['device_name' => 'Kitchen']);
        Setting::set('library_add_played_tracks', '1');

        $archive = $this->run_();

        $this->assertSame(0600, fileperms($archive) & 0777);
        $manifest = json_decode($this->entry($archive, 'manifest.json'), true);
        $this->assertSame('sqlite', $manifest['database']['driver']);
        $this->assertNotNull($manifest['database']['latest_migration']);
        $this->assertSame(1, $manifest['tables']['devices']);
        $this->assertArrayNotHasKey('sessions', $manifest['tables']);
        $this->assertArrayNotHasKey('jobs', $manifest['tables']);
        $this->assertStringContainsString('Kitchen', $this->entry($archive, 'data/devices.jsonl'));
        $this->assertNotNull($this->entry($archive, 'env-safe.txt'));
    }

    public function test_secrets_are_not_in_the_archive_by_default(): void
    {
        Setting::set('spotify_refresh_token', 'REFRESH-SECRET');
        Setting::set('spotify_client_secret', 'CLIENT-SECRET');
        Setting::set('lastfm_session_key', 'SESSION-SECRET');
        DB::table('users')->insert(['name' => 'admin', 'email' => 'a@b.c', 'password' => 'HASH-SECRET']);

        $archive = $this->run_();
        $zip = new ZipArchive;
        $zip->open($archive);
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $all .= $zip->getFromIndex($i);
        }
        $zip->close();

        foreach (['REFRESH-SECRET', 'CLIENT-SECRET', 'SESSION-SECRET', 'HASH-SECRET', (string) config('app.key')] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }

        $with = $this->run_(['--with-secrets' => true, '--path' => $this->dir.'/x']);
        $this->assertStringContainsString('HASH-SECRET', $this->entry($with, 'data/users.jsonl'));
        $this->assertTrue(json_decode($this->entry($with, 'manifest.json'), true)['with_secrets']);
        @unlink($with);
        @rmdir($this->dir.'/x');
    }

    public function test_round_trip_restores_data(): void
    {
        $device = Device::factory()->create(['device_name' => 'Kitchen']);
        Setting::set('library_add_played_tracks', '1');
        $archive = $this->run_();

        $device->delete();
        Setting::forget('library_add_played_tracks');
        Device::factory()->create(['device_name' => 'Other']);

        $this->artisan('backup:restore', ['archive' => $archive])->assertFailed();
        $this->assertSame('Other', Device::first()->device_name);

        $this->artisan('backup:restore', ['archive' => $archive, '--force' => true])->assertSuccessful();
        $this->assertSame(['Kitchen'], Device::pluck('device_name')->all());
        $this->assertSame('1', Setting::get('library_add_played_tracks'));
    }

    public function test_restore_into_empty_tables_needs_no_force(): void
    {
        Device::factory()->create(['device_name' => 'Kitchen']);
        $archive = $this->run_();
        DB::table('devices')->delete();
        DB::table('settings')->delete();

        $this->artisan('backup:restore', ['archive' => $archive])->assertSuccessful();
        $this->assertSame(1, Device::count());
    }

    public function test_restore_refuses_a_newer_backup(): void
    {
        $archive = $this->run_();
        $zip = new ZipArchive;
        $zip->open($archive);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $manifest['database']['latest_migration'] = '2099_01_01_000000_from_the_future';
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();

        $this->artisan('backup:restore', ['archive' => $archive, '--force' => true])
            ->expectsOutputToContain('newer version')->assertFailed();
    }

    public function test_restore_refuses_another_driver(): void
    {
        $archive = $this->run_();
        $zip = new ZipArchive;
        $zip->open($archive);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $manifest['database']['driver'] = 'mariadb';
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();

        $this->artisan('backup:restore', ['archive' => $archive, '--force' => true])
            ->expectsOutputToContain('db:import-sqlite')->assertFailed();
    }

    public function test_retention_prunes_only_matching_old_archives(): void
    {
        mkdir($this->dir, 0700, true);
        foreach (['20250101-000000', '20250102-000000', '20250103-000000'] as $stamp) {
            file_put_contents("{$this->dir}/remoment-backup-{$stamp}.zip", 'x');
        }
        file_put_contents($this->dir.'/notes.txt', 'mine');
        file_put_contents($this->dir.'/remoment-backup-old.zip', 'mine too');

        $this->run_(['--keep' => 2]);

        $names = array_map('basename', BackupStatus::archives($this->dir));
        $this->assertCount(2, $names);
        $this->assertContains('remoment-backup-20250103-000000.zip', $names);
        $this->assertFileDoesNotExist($this->dir.'/remoment-backup-20250101-000000.zip');
        $this->assertFileExists($this->dir.'/notes.txt');
        $this->assertFileExists($this->dir.'/remoment-backup-old.zip');
    }

    public function test_health_warns_when_missing_or_stale_and_not_when_fresh(): void
    {
        Livewire::test(SystemHealth::class)->assertSee('No backup exists yet');

        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir.'/remoment-backup-'.now()->subHours(60)->format('Ymd-His').'.zip', 'x');
        Livewire::test(SystemHealth::class)->assertSee('The last backup is from');

        file_put_contents($this->dir.'/remoment-backup-'.now()->subHour()->format('Ymd-His').'.zip', 'x');
        Livewire::test(SystemHealth::class)->assertDontSee('backup is from')->assertDontSee('No backup exists');
    }
}
