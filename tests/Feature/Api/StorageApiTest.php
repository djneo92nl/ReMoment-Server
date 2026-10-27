<?php

namespace Tests\Feature\Api;

use App\Jobs\EnrichTrackMusicBrainz;
use App\Models\File;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File as Fs;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** The admin storage API: locations and library files (docs/architecture/file-storage.md). */
class StorageApiTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('admin-api-login|127.0.0.1');
        User::factory()->create();
        $this->auth = ['Authorization' => 'Bearer '.$this->postJson('/api/admin/login', ['password' => 'password'])->json('token')];

        $this->base = sys_get_temp_dir().'/remoment-api-'.uniqid();
        Fs::makeDirectory($this->base.'/library', 0777, true);
    }

    protected function tearDown(): void
    {
        Fs::deleteDirectory($this->base);

        parent::tearDown();
    }

    private function wavUpload(string $name = 'Song.wav', ?string $artist = null): UploadedFile
    {
        $data = str_repeat("\x80", 8000);
        $path = $this->base.'/'.$name;
        $info = '';
        if ($artist !== null) {
            $info = 'INFO'.collect(['IART' => $artist, 'INAM' => 'Song'])->map(fn ($v, $k) => $k.pack('V', strlen($v) + 1).$v."\0".(strlen($v) % 2 ? '' : "\0"))->implode('');
            $info = 'LIST'.pack('V', strlen($info)).$info;
        }
        file_put_contents($path, 'RIFF'.pack('V', 36 + strlen($data) + strlen($info)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8).$info.'data'.pack('V', strlen($data)).$data);

        return new UploadedFile($path, $name, 'audio/wav', null, true);
    }

    public function test_it_needs_the_admin_token(): void
    {
        $this->getJson('/api/admin/storage/locations')->assertUnauthorized();
        $this->postJson('/api/admin/files')->assertUnauthorized();
    }

    public function test_a_location_is_created_without_leaking_its_secret(): void
    {
        $response = $this->postJson('/api/admin/storage/locations', [
            'name' => 'Cloud', 'driver' => 's3', 'role' => 'library',
            'config' => ['bucket' => 'music', 'key' => 'AKIA', 'secret' => 'shh', 'endpoint' => 'http://minio:9000'],
        ], $this->auth)->assertCreated();

        $response->assertJsonPath('config.bucket', 'music')->assertJsonMissingPath('config.secret')->assertJsonMissingPath('config.key');
        $this->assertSame('shh', StorageLocation::firstOrFail()->config['secret']);
        $this->assertStringNotContainsString('shh', (string) \DB::table('storage_locations')->value('config'));
    }

    public function test_a_local_location_needs_a_root(): void
    {
        $this->postJson('/api/admin/storage/locations', ['name' => 'x', 'driver' => 'local', 'config' => []], $this->auth)->assertUnprocessable();
    }

    public function test_upload_files_and_delete_through_the_api(): void
    {
        $this->postJson('/api/admin/files', ['file' => $this->wavUpload()], $this->auth)->assertUnprocessable(); // no library yet

        $this->postJson('/api/admin/storage/locations', ['name' => 'library', 'driver' => 'local', 'config' => ['root' => $this->base.'/library']], $this->auth)->assertCreated();

        $upload = $this->post('/api/admin/files', ['file' => $this->wavUpload()], $this->auth + ['Accept' => 'application/json'])->assertCreated();
        $upload->assertJsonPath('file.path', 'Unknown Artist/Unknown Album/Song.wav');
        $trackId = $upload->json('track_id');

        $this->getJson("/api/admin/tracks/{$trackId}/files", $this->auth)->assertOk()->assertJsonPath('data.0.is_primary', true);

        $fileId = File::firstOrFail()->id;
        $this->postJson("/api/admin/files/{$fileId}/move", ['path' => 'renamed/Song.wav'], $this->auth)->assertOk()->assertJsonPath('path', 'renamed/Song.wav');
        $this->postJson("/api/admin/files/{$fileId}/verify", [], $this->auth)->assertOk()->assertJsonPath('result', 'ok');

        $this->deleteJson("/api/admin/tracks/{$trackId}/files", [], $this->auth)->assertOk();
        $this->assertSame(0, File::count());
        $this->assertSame(0, Track::count());
        $this->assertFileDoesNotExist($this->base.'/library/renamed/Song.wav');
    }

    public function test_an_uploaded_track_gets_its_enrichment_queued(): void
    {
        Queue::fake();
        StorageLocation::create(['name' => 'library', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => $this->base.'/library']]);

        $this->post('/api/admin/files', ['file' => $this->wavUpload('Song.wav', 'Some Artist')], $this->auth + ['Accept' => 'application/json'])->assertCreated();

        Queue::assertPushed(EnrichTrackMusicBrainz::class);
    }

    public function test_uploading_the_same_file_twice(): void
    {
        Queue::fake();
        StorageLocation::create(['name' => 'library', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => $this->base.'/library']]);
        $headers = $this->auth + ['Accept' => 'application/json'];

        $this->post('/api/admin/files', ['file' => $this->wavUpload('Song.wav', 'Some Artist')], $headers)->assertCreated();
        $this->post('/api/admin/files', ['file' => $this->wavUpload('Song.wav', 'Some Artist')], $headers)->assertCreated();

        $this->assertSame(1, Track::count());
        $this->assertSame(1, File::count());
        $this->assertSame('Some Artist/Unknown Album/Song.wav', File::firstOrFail()->path);
        $this->assertFileExists($this->base.'/library/Some Artist/Unknown Album/Song.wav');
        $this->assertFileDoesNotExist($this->base.'/library/Some Artist/Unknown Album/Song (2).wav');
        $this->assertSame([], glob($this->base.'/library/_uploads/*') ?: []); // no staging folder left behind
        $this->assertSame(1, Track::firstOrFail()->trackFiles()->count());
    }

    public function test_a_location_with_files_cannot_be_removed(): void
    {
        $location = StorageLocation::create(['name' => 'library', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => $this->base.'/library']]);
        $this->post('/api/admin/files', ['file' => $this->wavUpload()], $this->auth + ['Accept' => 'application/json'])->assertCreated();

        $this->deleteJson("/api/admin/storage/locations/{$location->id}", [], $this->auth)->assertUnprocessable();
    }

    public function test_scan_and_test_endpoints(): void
    {
        $location = StorageLocation::create(['name' => 'library', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => $this->base.'/library']]);
        copy($this->wavUpload('a.wav')->getRealPath(), $this->base.'/library/a.wav');

        $this->postJson("/api/admin/storage/locations/{$location->id}/test", [], $this->auth)->assertOk();
        $this->postJson("/api/admin/storage/locations/{$location->id}/scan", [], $this->auth)->assertOk()->assertJsonPath('changed', 1);
        $this->assertSame(1, Track::count());
    }
}
