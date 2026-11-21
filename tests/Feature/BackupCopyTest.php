<?php

namespace Tests\Feature;

use App\Domain\Alerts\Alerts;
use App\Domain\Backup\BackupCopy;
use App\Domain\Backup\BackupStatus;
use App\Livewire\BackupCopyCard;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** The optional second copy of every backup: a path only, atomic, same retention, never fails the backup. */
class BackupCopyTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsAdmin;

    private string $dir;

    private string $target;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/remoment-copy-test-'.uniqid();
        $this->dir = $base.'/backups';
        $this->target = $base.'/nas';
        mkdir($this->target, 0700, true);
        config(['backup.path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir, $this->target] as $d) {
            @chmod($d, 0700);
            foreach (glob($d.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
                is_file($f) && @unlink($f);
            }
            @rmdir($d);
        }
        @rmdir(dirname($this->dir));
        parent::tearDown();
    }

    private function backup(array $options = []): int
    {
        return Artisan::call('backup:run', $options);
    }

    private function fakeArchive(string $dir, string $stamp): void
    {
        file_put_contents("{$dir}/remoment-backup-{$stamp}.zip", 'old');
    }

    public function test_without_a_path_nothing_is_copied_and_nothing_is_recorded(): void
    {
        $this->assertSame(0, $this->backup());

        $this->assertSame([], glob($this->target.'/*'));
        $this->assertNull(BackupCopy::status());
        $this->assertFalse(Alerts::isActive(BackupCopy::ALERT));
    }

    public function test_the_archive_is_copied_after_a_successful_backup_and_leaves_no_partial_file(): void
    {
        BackupCopy::setPath($this->target);

        $this->assertSame(0, $this->backup());

        $archives = BackupStatus::archives($this->dir);
        $copies = BackupStatus::archives($this->target);
        $this->assertCount(1, $copies);
        $this->assertSame(basename($archives[0]), basename($copies[0]));
        $this->assertSame(filesize($archives[0]), filesize($copies[0]));
        $this->assertSame([], glob($this->target.'/*.partial'));
        $this->assertTrue(BackupCopy::status()['ok']);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($copies[0])), -4));
    }

    public function test_the_copy_keeps_as_many_archives_as_the_source_and_never_touches_other_files(): void
    {
        BackupCopy::setPath($this->target);
        $this->fakeArchive($this->target, '20200101-010101');
        $this->fakeArchive($this->target, '20200102-010101');
        $this->fakeArchive($this->target, '20200103-010101');
        file_put_contents($this->target.'/holiday-photos.zip', 'keep me');

        $this->assertSame(0, $this->backup(['--keep' => 2]));

        $names = array_map('basename', BackupStatus::archives($this->target));
        $this->assertCount(2, $names);
        $this->assertNotContains('remoment-backup-20200101-010101.zip', $names);
        $this->assertNotContains('remoment-backup-20200102-010101.zip', $names);
        $this->assertFileExists($this->target.'/holiday-photos.zip');
    }

    public function test_a_missing_directory_fails_the_copy_with_an_alert_but_not_the_backup(): void
    {
        BackupCopy::setPath($this->target.'/not-mounted');

        $this->assertSame(0, $this->backup());

        $this->assertCount(1, BackupStatus::archives($this->dir));
        $this->assertDirectoryDoesNotExist($this->target.'/not-mounted');
        $this->assertFalse(BackupCopy::status()['ok']);
        $this->assertStringContainsString('does not exist', BackupCopy::status()['message']);
        $this->assertTrue(Alerts::isActive(BackupCopy::ALERT));
    }

    public function test_the_alert_resolves_when_a_later_copy_works(): void
    {
        BackupCopy::setPath($this->target.'/not-mounted');
        $this->backup();
        $this->assertTrue(Alerts::isActive(BackupCopy::ALERT));

        mkdir($this->target.'/not-mounted');
        $this->assertSame(0, $this->backup());

        $this->assertFalse(Alerts::isActive(BackupCopy::ALERT));
        $this->assertTrue(BackupCopy::status()['ok']);
        @array_map('unlink', glob($this->target.'/not-mounted/*'));
        @rmdir($this->target.'/not-mounted');
    }

    public function test_an_unwritable_directory_is_reported(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write anywhere');
        }
        chmod($this->target, 0500);

        $result = BackupCopy::usable($this->target);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not writable', $result['message']);
    }

    public function test_relative_paths_dotdot_and_the_backup_directory_itself_are_refused(): void
    {
        mkdir($this->dir, 0700, true);

        $this->assertFalse(BackupCopy::usable('relative/dir')['ok']);
        $this->assertFalse(BackupCopy::usable($this->target.'/../nas')['ok']);
        $this->assertStringContainsString('backup directory itself', BackupCopy::usable($this->dir)['message']);
        $this->assertTrue(BackupCopy::usable($this->target)['ok']);
        $this->assertSame([], glob($this->target.'/.remoment-write-test-*'));
    }

    public function test_an_archive_with_credentials_is_not_copied(): void
    {
        BackupCopy::setPath($this->target);

        $this->assertSame(0, $this->backup(['--with-secrets' => true]));

        $this->assertSame([], BackupStatus::archives($this->target));
        $this->assertStringContainsString('Not copied', Artisan::output());
    }

    public function test_no_copy_skips_it(): void
    {
        BackupCopy::setPath($this->target);

        $this->assertSame(0, $this->backup(['--no-copy' => true]));

        $this->assertSame([], BackupStatus::archives($this->target));
    }

    public function test_clearing_the_path_resolves_the_alert_and_forgets_the_status(): void
    {
        BackupCopy::setPath($this->target.'/gone');
        $this->backup();
        $this->assertTrue(Alerts::isActive(BackupCopy::ALERT));

        BackupCopy::setPath('');

        $this->assertNull(BackupCopy::path());
        $this->assertNull(BackupCopy::status());
        $this->assertFalse(Alerts::isActive(BackupCopy::ALERT));
    }

    public function test_the_api_needs_the_admin_token(): void
    {
        $this->assertNeedsAdminToken([['GET', '/api/admin/backup/copy-path'], ['PUT', '/api/admin/backup/copy-path'], ['POST', '/api/admin/backup/copy-path/test']]);
    }

    public function test_the_api_reads_saves_tests_and_clears_the_path(): void
    {
        $headers = $this->adminHeaders();

        $this->getJson('/api/admin/backup/copy-path', $headers)->assertOk()->assertJsonPath('path', null)->assertJsonPath('last_copy', null)->assertJsonPath('keep', 7);

        $this->postJson('/api/admin/backup/copy-path/test', ['path' => $this->target], $headers)->assertOk()->assertJsonPath('ok', true)->assertJsonPath('path', $this->target);
        $this->postJson('/api/admin/backup/copy-path/test', [], $headers)->assertStatus(422);
        $this->assertNull(BackupCopy::path(), 'a test saves nothing');

        $this->putJson('/api/admin/backup/copy-path', ['path' => $this->target.'/'], $headers)->assertOk()->assertJsonPath('path', $this->target);
        $this->postJson('/api/admin/backup/copy-path/test', [], $headers)->assertOk()->assertJsonPath('ok', true);

        $this->putJson('/api/admin/backup/copy-path', ['path' => null], $headers)->assertOk()->assertJsonPath('path', null);
        $this->assertNull(Setting::get(BackupCopy::SETTING));
    }

    public function test_the_api_refuses_an_unusable_path_and_keeps_the_old_one(): void
    {
        $headers = $this->adminHeaders();
        BackupCopy::setPath($this->target);

        $this->putJson('/api/admin/backup/copy-path', ['path' => $this->target.'/missing'], $headers)->assertStatus(422)->assertJsonValidationErrors('path');
        $this->putJson('/api/admin/backup/copy-path', ['path' => 'relative'], $headers)->assertStatus(422);
        $this->putJson('/api/admin/backup/copy-path', [], $headers)->assertStatus(422);

        $this->assertSame($this->target, BackupCopy::path());
    }

    public function test_the_card_is_for_the_admin_only(): void
    {
        BackupCopy::setPath($this->target);

        Livewire::test(BackupCopyCard::class)->assertDontSee('/nas')->call('test')->assertForbidden();

        $this->actingAs(User::factory()->create());
        Livewire::test(BackupCopyCard::class)->assertSee('Backup copy')->assertSet('path', $this->target);
    }

    public function test_the_card_tests_saves_and_switches_off(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(BackupCopyCard::class)
            ->set('path', $this->target.'/nope')->call('test')->assertSee('does not exist')
            ->set('path', $this->target)->call('test')->assertSee('is writable')
            ->call('save')->assertSee('Every new backup is copied there');
        $this->assertSame($this->target, BackupCopy::path());

        Livewire::test(BackupCopyCard::class)->set('path', $this->target.'/nope')->call('save')->assertSee('does not exist');
        $this->assertSame($this->target, BackupCopy::path(), 'an unusable path is not saved');

        Livewire::test(BackupCopyCard::class)->set('path', '')->call('save')->assertSee('No copy');
        $this->assertNull(BackupCopy::path());
    }

    public function test_the_card_shows_the_last_copy(): void
    {
        $this->actingAs(User::factory()->create());
        BackupCopy::setPath($this->target);
        $this->backup();

        Livewire::test(BackupCopyCard::class)->assertSee('Last copy');
    }

    public function test_the_health_page_carries_the_card_for_the_admin(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings/health')->assertOk()->assertSee('data-test="backup-copy"', false);
    }
}
