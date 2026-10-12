<?php

namespace Tests\Feature;

use App\Domain\Storage\StorageTasks;
use App\Jobs\RunStorageTask;
use App\Livewire\StorageFiles;
use App\Livewire\StorageManager;
use App\Models\File;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File as Fs;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** /settings/storage and the queued file tasks (docs/architecture/file-storage.md). */
class StorageSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->base = sys_get_temp_dir().'/remoment-settings-'.uniqid();
        Fs::makeDirectory($this->base.'/library', 0777, true);
    }

    protected function tearDown(): void
    {
        Fs::deleteDirectory($this->base);

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    private function wav(int $seed = 0): string
    {
        $data = str_repeat(chr(0x80 + $seed), 8000);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8).'data'.pack('V', strlen($data)).$data;
    }

    private function library(): StorageLocation
    {
        return StorageLocation::create(['name' => 'library', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => $this->base.'/library']]);
    }

    public function test_the_page_is_for_the_admin_only(): void
    {
        $this->get('/settings/storage')->assertRedirect('/login');

        $this->actingAs($this->admin())->get('/settings/storage')->assertOk()->assertSee('File Storage');
    }

    public function test_the_settings_index_links_to_it(): void
    {
        $this->actingAs($this->admin())->get('/settings')->assertOk()->assertSee('File Storage');
    }

    public function test_a_local_location_is_added_and_a_missing_folder_refused(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(StorageManager::class)
            ->call('newLocation')
            ->set('name', 'Nope')->set('root', $this->base.'/does-not-exist')
            ->call('save')->assertHasErrors('root');

        Livewire::test(StorageManager::class)
            ->call('newLocation')
            ->set('name', 'Music')->set('root', $this->base.'/library/')
            ->call('save')->assertHasNoErrors();

        $this->assertSame($this->base.'/library', StorageLocation::firstOrFail()->config['root']);
    }

    public function test_editing_keeps_the_s3_secret_when_left_blank(): void
    {
        $this->actingAs($this->admin());
        $location = StorageLocation::create(['name' => 'Cloud', 'driver' => 's3', 'role' => 'library', 'config' => ['bucket' => 'b', 'key' => 'AKIA', 'secret' => 'shh']]);

        $component = Livewire::test(StorageManager::class)->call('edit', $location->id);
        $this->assertSame('', $component->get('secret'));

        $component->set('bucket', 'other')->call('save')->assertHasNoErrors();

        $config = $location->refresh()->config;
        $this->assertSame('other', $config['bucket']);
        $this->assertSame('shh', $config['secret']);
        $this->assertSame('AKIA', $config['key']);
    }

    public function test_scan_is_queued_once(): void
    {
        Queue::fake();
        $this->actingAs($this->admin());
        $location = $this->library();

        $component = Livewire::test(StorageManager::class)->call('scan', $location->id);
        Queue::assertPushed(RunStorageTask::class, fn ($job) => $job->task === 'scan' && $job->locationId === $location->id);
        $this->assertSame('queued', StorageTasks::status('scan', $location->id)['state']);

        $component->call('scan', $location->id);
        Queue::assertPushed(RunStorageTask::class, 1);
    }

    public function test_the_job_scans_and_reports_status(): void
    {
        $location = $this->library();
        file_put_contents($this->base.'/library/a.wav', $this->wav());

        StorageTasks::queue('scan', $location->id);

        $status = StorageTasks::status('scan', $location->id);
        $this->assertSame('done', $status['state']); // the test queue runs jobs synchronously
        $this->assertStringContainsString('1 new', $status['message']);
        $this->assertSame(1, Track::count());
        $this->assertFalse(StorageTasks::isActive('scan', $location->id));
    }

    public function test_a_failed_job_reports_why(): void
    {
        $job = new RunStorageTask('scan', 999);
        $job->failed(new \RuntimeException('disk gone'));

        $this->assertSame(['failed', 'disk gone'], [StorageTasks::status('scan', 999)['state'], StorageTasks::status('scan', 999)['message']]);
    }

    public function test_a_lost_task_can_be_queued_again(): void
    {
        Queue::fake();
        StorageTasks::put('import', null, ['state' => 'queued']);
        $this->assertTrue(StorageTasks::isActive('import'));

        $this->travel(3)->hours();
        $this->assertFalse(StorageTasks::isActive('import'));
        $this->assertTrue(StorageTasks::queue('import'));
    }

    public function test_the_import_task_moves_the_inbox_into_the_library(): void
    {
        $this->library();
        Fs::makeDirectory($this->base.'/inbox');
        StorageLocation::create(['name' => 'inbox', 'driver' => 'local', 'role' => 'inbox', 'config' => ['root' => $this->base.'/inbox']]);
        file_put_contents($this->base.'/inbox/x.wav', $this->wav());

        StorageTasks::queue('import');

        $this->assertSame('1 files imported.', StorageTasks::status('import')['message']);
        $this->assertFileExists($this->base.'/library/Unknown Artist/Unknown Album/x.wav');
    }

    public function test_the_scan_command_can_queue(): void
    {
        Queue::fake();
        $this->library();

        $this->artisan('library:scan-files --queue')->assertSuccessful();

        Queue::assertPushed(RunStorageTask::class);
    }

    public function test_a_location_with_files_is_not_removed_from_the_page(): void
    {
        $this->actingAs($this->admin());
        $location = $this->library();
        file_put_contents($this->base.'/library/a.wav', $this->wav());
        StorageTasks::queue('scan', $location->id);

        Livewire::test(StorageManager::class)->call('delete', $location->id);

        $this->assertSame(1, StorageLocation::count());
    }

    public function test_the_file_browser_filters_renames_and_deletes(): void
    {
        $this->actingAs($this->admin());
        $location = $this->library();
        file_put_contents($this->base.'/library/alpha.wav', $this->wav(0));
        file_put_contents($this->base.'/library/beta.wav', $this->wav(1));
        StorageTasks::queue('scan', $location->id);
        $alpha = File::where('path', 'alpha.wav')->firstOrFail();

        Livewire::test(StorageFiles::class)
            ->assertSee('alpha.wav')->assertSee('beta.wav')
            ->set('search', 'alp')->assertSee('alpha.wav')->assertDontSee('beta.wav')
            ->call('startRename', $alpha->id)->set('renamePath', 'renamed/alpha.wav')->call('saveRename')
            ->assertSee('renamed/alpha.wav');

        $this->assertFileExists($this->base.'/library/renamed/alpha.wav');

        Livewire::test(StorageFiles::class)->call('delete', $alpha->id);
        $this->assertFileDoesNotExist($this->base.'/library/renamed/alpha.wav');
        $this->assertSame(1, File::count());
    }

    public function test_the_api_can_queue_a_scan(): void
    {
        Queue::fake();
        \Illuminate\Support\Facades\RateLimiter::clear('admin-api-login|127.0.0.1');
        $this->admin();
        $token = $this->postJson('/api/admin/login', ['password' => 'password'])->json('token');
        $location = $this->library();
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->postJson("/api/admin/storage/locations/{$location->id}/scan?queue=1", [], $auth)->assertStatus(202);
        $this->postJson("/api/admin/storage/locations/{$location->id}/scan?queue=1", [], $auth)->assertStatus(409);
    }
}
