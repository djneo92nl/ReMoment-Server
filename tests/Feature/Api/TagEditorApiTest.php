<?php

namespace Tests\Feature\Api;

use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MakesTaggedFiles;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** The admin tag editor API: /api/admin/files/{id}/tags|cover and /api/admin/albums/{id}/tags|cover (docs/api/tag-editor.md). */
class TagEditorApiTest extends TestCase
{
    use MakesTaggedFiles;
    use RefreshDatabase;
    use SignsInAsAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\App\Domain\Storage\TagWriter::available()) {
            $this->markTestSkipped('ffmpeg is not available.');
        }
        $this->makeBase();
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->removeBase();
        parent::tearDown();
    }

    private function album(int $files = 2): Album
    {
        $library = $this->location('library', 'library');
        foreach (range(1, $files) as $n) {
            $this->makeAudio('library', "raw/{$n}.mp3", ['title' => "Raw {$n}", 'artist' => 'Band', 'album' => 'Raw Album', 'track' => (string) $n], $n === 1 ? $this->jpeg() : null);
        }
        $this->scan($library);

        return Album::where('name', 'Raw Album')->firstOrFail();
    }

    public function test_every_route_needs_the_admin_token(): void
    {
        $this->album(1);
        $this->assertNeedsAdminToken([
            ['GET', '/api/admin/files/1/tags'], ['PUT', '/api/admin/files/1/tags'], ['POST', '/api/admin/files/1/tags/undo'],
            ['POST', '/api/admin/files/1/cover'], ['DELETE', '/api/admin/files/1/cover'],
            ['GET', '/api/admin/albums/1/tags'], ['PUT', '/api/admin/albums/1/tags'], ['POST', '/api/admin/albums/1/tags/undo'], ['POST', '/api/admin/albums/1/cover'],
        ]);
    }

    public function test_show_reads_the_tags_from_the_file(): void
    {
        $this->album();
        $file = File::orderBy('path')->first();

        $this->getJson("/api/admin/files/{$file->id}/tags", $this->adminHeaders())->assertOk()
            ->assertJsonPath('tags.title', 'Raw 1')->assertJsonPath('tags.album', 'Raw Album')->assertJsonPath('tags.track_number', '1')
            ->assertJsonPath('has_cover', true)->assertJsonPath('can_undo', false)->assertJsonPath('editable', true)->assertJsonPath('cover_editable', true)
            ->assertJsonPath('id', $file->id);
    }

    public function test_put_changes_tags_clears_fields_and_can_be_undone(): void
    {
        $this->album();
        $file = File::orderBy('path')->first();
        $headers = $this->adminHeaders();

        $this->putJson("/api/admin/files/{$file->id}/tags", ['title' => 'Better', 'genre' => 'Jazz', 'organize' => false], $headers)->assertOk()
            ->assertJsonPath('tags.title', 'Better')->assertJsonPath('tags.genre', 'Jazz')->assertJsonPath('can_undo', true)->assertJsonPath('path', $file->path);

        $this->putJson("/api/admin/files/{$file->id}/tags", ['clear' => ['genre'], 'organize' => false], $headers)->assertOk()->assertJsonPath('tags.genre', '');

        $this->postJson("/api/admin/files/{$file->id}/tags/undo", [], $headers)->assertOk()->assertJsonPath('tags.genre', 'Jazz')->assertJsonPath('can_undo', false);
        $this->postJson("/api/admin/files/{$file->id}/tags/undo", [], $headers)->assertStatus(422)->assertJsonPath('error', 'invalid');
    }

    public function test_put_refuses_nonsense(): void
    {
        $this->album();
        $file = File::first();
        $headers = $this->adminHeaders();

        $this->putJson("/api/admin/files/{$file->id}/tags", ['track_number' => 'x'], $headers)->assertStatus(422);
        $this->putJson("/api/admin/files/{$file->id}/tags", ['year' => 'soon'], $headers)->assertStatus(422)->assertJsonPath('error', 'invalid');
        $this->putJson("/api/admin/files/{$file->id}/tags", [], $headers)->assertStatus(422)->assertJsonPath('message', 'Nothing to change.');
    }

    public function test_a_cover_is_set_from_an_upload_or_base64_and_removed(): void
    {
        $this->album();
        $file = File::orderBy('path')->first();
        $headers = $this->adminHeaders();

        $upload = UploadedFile::fake()->createWithContent('cover.jpg', $this->jpeg(0x2244AA));
        $this->post("/api/admin/files/{$file->id}/cover", ['image' => $upload], $headers + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('has_cover', true)->assertJsonPath('can_undo', true);

        $this->postJson("/api/admin/files/{$file->id}/cover", ['image_base64' => 'data:image/jpeg;base64,'.base64_encode($this->jpeg(0x44AA22))], $headers)->assertOk();

        $this->postJson("/api/admin/files/{$file->id}/cover", ['image_base64' => base64_encode('not a picture')], $headers)->assertStatus(422)->assertJsonPath('error', 'invalid');
        $this->postJson("/api/admin/files/{$file->id}/cover", [], $headers)->assertStatus(422);

        $this->deleteJson("/api/admin/files/{$file->id}/cover", [], $headers)->assertOk()->assertJsonPath('has_cover', false);
    }

    public function test_the_album_form_lists_files_and_the_batch_edit_changes_them_all(): void
    {
        $album = $this->album(3);
        $headers = $this->adminHeaders();
        $files = File::orderBy('path')->get();

        $this->getJson("/api/admin/albums/{$album->id}/tags", $headers)->assertOk()
            ->assertJsonCount(3, 'files')->assertJsonPath('files.0.tags.title', 'Raw 1')->assertJsonPath('can_undo', false);

        $response = $this->putJson("/api/admin/albums/{$album->id}/tags", [
            'tags' => ['album' => 'Fixed', 'year' => '2010'],
            'files' => [(string) $files[0]->id => ['title' => 'One'], (string) $files[1]->id => ['title' => 'Two', 'track_number' => 2]],
        ], $headers)->assertOk();

        $response->assertJsonCount(3, 'edited')->assertJsonPath('failed', [])->assertJsonPath('skipped', []);
        $newId = $response->json('album_id');
        $this->assertSame('Fixed', Album::findOrFail($newId)->name);
        $this->assertEqualsCanonicalizing(['One', 'Two', 'Raw 3'], Track::where('album_id', $newId)->pluck('name')->all());

        $this->getJson("/api/admin/albums/{$newId}/tags", $headers)->assertOk()->assertJsonPath('can_undo', true);
        $this->postJson("/api/admin/albums/{$newId}/tags/undo", [], $headers)->assertOk()->assertJsonCount(3, 'undone');
        $this->assertEqualsCanonicalizing(['Raw 1', 'Raw 2', 'Raw 3'], Track::pluck('name')->all());
    }

    public function test_an_album_cover_goes_into_every_file(): void
    {
        $album = $this->album(2);

        $this->postJson("/api/admin/albums/{$album->id}/cover", ['image_base64' => base64_encode($this->jpeg(0x33AA33))], $this->adminHeaders())
            ->assertOk()->assertJsonCount(2, 'edited');

        $this->assertStringStartsWith('remoment:cover/', Album::firstOrFail()->images[0]['url']);
    }

    public function test_an_album_without_own_files_or_nothing_to_change_is_a_422(): void
    {
        $headers = $this->adminHeaders();
        $empty = Album::create(['artist_id' => \App\Models\Media\Artist::create(['name' => 'X'])->id, 'name' => 'No Files']);

        $this->putJson("/api/admin/albums/{$empty->id}/tags", ['tags' => ['year' => '2000']], $headers)->assertStatus(422)->assertJsonPath('error', 'invalid');

        $album = $this->album();
        $this->putJson("/api/admin/albums/{$album->id}/tags", [], $headers)->assertStatus(422)->assertJsonPath('message', 'Nothing to change.');
    }
}
