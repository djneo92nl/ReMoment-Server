<?php

namespace Tests\Feature;

use App\Domain\Storage\LibraryUploads;
use App\Jobs\ProcessLibraryUpload;
use App\Livewire\LibraryUploader;
use App\Models\File;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File as Fs;
use Livewire\Livewire;
use Tests\TestCase;

/** The browser drop zone on /settings/storage: register, send, parse, copy, enrich. */
class LibraryUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->base = sys_get_temp_dir().'/remoment-upload-'.uniqid();
        Fs::makeDirectory($this->base.'/library', 0777, true);
        Fs::makeDirectory($this->base.'/inbox', 0777, true);
        StorageLocation::create(['name' => 'library', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => $this->base.'/library']]);
        StorageLocation::create(['name' => 'inbox', 'driver' => 'local', 'role' => 'inbox', 'config' => ['root' => $this->base.'/inbox']]);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Fs::deleteDirectory($this->base);

        parent::tearDown();
    }

    private function wav(): string
    {
        $data = str_repeat(chr(0x80), 8000);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8).'data'.pack('V', strlen($data)).$data;
    }

    public function test_a_dropped_file_goes_through_every_step_into_the_library(): void
    {
        $id = $this->postJson('/settings/storage/uploads', ['name' => 'song.wav', 'size' => 8044])
            ->assertOk()->assertJson(['accepted' => true])->json('id');
        $this->assertSame(LibraryUploads::UPLOADING, LibraryUploads::find($id)['state']);

        Bus::fake([ProcessLibraryUpload::class]);
        $this->post("/settings/storage/uploads/{$id}", ['file' => UploadedFile::fake()->createWithContent('song.wav', $this->wav())])->assertStatus(202);
        Bus::assertDispatched(ProcessLibraryUpload::class);
        $this->assertSame(LibraryUploads::PARSING, LibraryUploads::find($id)['state']);

        (new ProcessLibraryUpload($id, StorageLocation::library()->id, '_uploads/'.basename(dirname(collect(Fs::allFiles($this->base.'/library/_uploads'))->first()->getPathname())).'/song.wav'))
            ->handle(app(\App\Domain\Storage\LibraryFiles::class));

        $row = LibraryUploads::find($id);
        $this->assertSame(LibraryUploads::ENRICHING, $row['state']);
        $this->assertSame('Unknown Artist/Unknown Album/song.wav', $row['path']);
        $this->assertFileExists($this->base.'/library/Unknown Artist/Unknown Album/song.wav');
        $this->assertSame(1, Track::count());
        $this->assertSame(StorageLocation::library()->id, File::firstOrFail()->storage_location_id);
        $this->assertSame([], Fs::allFiles($this->base.'/inbox'));
        $this->assertSame([], glob($this->base.'/library/_uploads/*') ?: []);
    }

    public function test_a_non_audio_file_is_refused_at_once(): void
    {
        $id = $this->postJson('/settings/storage/uploads', ['name' => 'notes.txt', 'size' => 5])
            ->assertOk()->assertJson(['accepted' => false])->json('id');

        $row = LibraryUploads::find($id);
        $this->assertSame(LibraryUploads::FAILED, $row['state']);
        $this->assertSame('Not an audio file.', $row['message']);
    }

    public function test_an_unreadable_audio_file_fails_the_parse_step(): void
    {
        $id = LibraryUploads::start('bad.mp3', 3)['id'];
        $files = app(\App\Domain\Storage\LibraryFiles::class);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'abc');
        rewind($stream);
        [$location, $path] = $files->stageUpload('bad.mp3', $stream);

        try {
            (new ProcessLibraryUpload($id, $location->id, $path))->handle($files);
            $this->fail('Expected the parse to fail.');
        } catch (\InvalidArgumentException $e) {
            (new ProcessLibraryUpload($id, $location->id, $path))->failed($e);
        }

        $row = LibraryUploads::find($id);
        $this->assertSame(LibraryUploads::FAILED, $row['state']);
        $this->assertSame(LibraryUploads::PARSING, $row['failed_at']);
        $this->assertSame([], Fs::allFiles($this->base.'/library/_uploads'));
    }

    public function test_the_table_shows_the_steps_and_clears_finished_rows(): void
    {
        $uploading = LibraryUploads::start('a.flac', 10)['id'];
        $failed = LibraryUploads::start('b.mp3', 10)['id'];
        LibraryUploads::fail($failed, 'Not an audio file.');

        Livewire::test(LibraryUploader::class)
            ->assertSee('a.flac')->assertSee('b.mp3')->assertSee('Upload')->assertSee('Enrich')
            ->call('clear')
            ->assertSee('a.flac')->assertDontSee('b.mp3');

        $this->assertSame($uploading, LibraryUploads::all()[0]['id']);
    }

    public function test_the_page_has_the_drop_zone_for_the_admin_only(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings/storage')->assertOk()->assertSee('Add music');
        auth()->logout();
        $this->postJson('/settings/storage/uploads', ['name' => 'a.mp3', 'size' => 1])->assertUnauthorized();
    }
}
