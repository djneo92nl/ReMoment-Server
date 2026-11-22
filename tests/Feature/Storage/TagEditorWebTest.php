<?php

namespace Tests\Feature\Storage;

use App\Domain\Storage\AudioTags;
use App\Livewire\AlbumTagEditor;
use App\Livewire\StorageFiles;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\MakesTaggedFiles;
use Tests\TestCase;

/** The tag editor in the web UI: the file dialog on /settings/storage and the album page (docs/frontend/tag-editor.md). */
class TagEditorWebTest extends TestCase
{
    use MakesTaggedFiles;
    use RefreshDatabase;

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
            $this->makeAudio('library', "raw/{$n}.mp3", ['title' => "Raw {$n}", 'artist' => 'Band', 'album' => 'Raw Album', 'track' => (string) $n, 'date' => $n === 1 ? '1999' : '2000'], $n === 1 ? $this->jpeg() : null);
        }
        $this->scan($library);

        return Album::where('name', 'Raw Album')->firstOrFail();
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    public function test_the_album_page_is_admin_only_and_linked_from_the_file_menu(): void
    {
        $album = $this->album();

        $this->get("/settings/storage/albums/{$album->id}")->assertRedirect();

        $this->actingAs($this->admin())->get("/settings/storage/albums/{$album->id}")->assertOk()->assertSee('Edit album')->assertSee('Raw Album');
        Livewire::test(StorageFiles::class)->call('open', 'raw')->assertSee('Edit the whole album…');
    }

    public function test_the_album_editor_shows_what_differs_and_writes_only_what_changed(): void
    {
        $this->actingAs($this->admin());
        $album = $this->album();
        $files = File::orderBy('path')->get();

        $component = Livewire::test(AlbumTagEditor::class, ['album' => $album])
            ->assertSet('fields.album', 'Raw Album')->assertSet('fields.year', '')->assertSet('mixed', ['year'])
            ->assertSet("rows.{$files[0]->id}.title", 'Raw 1');

        $component->set('fields.album', 'Fixed')->set("rows.{$files[1]->id}.title", 'Second')->call('save');

        $component->assertSet('error', null)->assertSee('2 files rewritten.');
        $fixed = Album::where('name', 'Fixed')->firstOrFail();
        $this->assertSame(['Raw 1', 'Second'], Track::where('album_id', $fixed->id)->orderBy('id')->pluck('name')->all());
        $this->assertSame('1999', AudioTags::read($this->base.'/library/'.File::orderBy('id')->first()->path)['year'], 'the year the files disagreed on was left alone');
        $component->assertSet('albumId', $fixed->id)->assertSet('canUndo', true);

        $component->call('undo')->assertSee('2 edits undone.');
        $this->assertSame(['Raw Album'], Album::pluck('name')->all());
    }

    public function test_a_cover_picked_in_the_album_editor_goes_into_every_file(): void
    {
        $this->actingAs($this->admin());
        $album = $this->album(2);

        Livewire::test(AlbumTagEditor::class, ['album' => $album])
            ->set('cover', UploadedFile::fake()->image('cover.jpg', 300, 300))->assertHasNoErrors('cover')
            ->call('save')->assertSee('2 files rewritten.');

        foreach (File::all() as $file) {
            $this->assertNotEmpty(AudioTags::read($this->base.'/library/'.$file->path)['picture']['data']);
        }
        $this->assertStringStartsWith('remoment:cover/', Album::firstOrFail()->images[0]['url']);
    }

    public function test_a_save_with_nothing_changed_says_so_and_a_non_picture_is_refused(): void
    {
        $this->actingAs($this->admin());
        $album = $this->album(1);

        Livewire::test(AlbumTagEditor::class, ['album' => $album])->call('save')->assertSet('error', 'Nothing to change.');
        Livewire::test(AlbumTagEditor::class, ['album' => $album])
            ->set('cover', UploadedFile::fake()->create('notes.txt', 3, 'text/plain'))->assertHasErrors('cover');
    }

    public function test_the_file_dialog_writes_only_changed_fields_and_undoes(): void
    {
        $this->actingAs($this->admin());
        $this->album(1);
        $file = File::firstOrFail();

        $component = Livewire::test(StorageFiles::class)->call('startEdit', $file->id)
            ->assertSet('tags.title', 'Raw 1')->assertSet('state.has_cover', true)->assertSet('state.editable', true)
            ->assertSee('data-test="cover-editor"', false);

        $component->set('tags.title', 'Better')->call('saveTags')->assertSet('editing', null);
        $this->assertSame('Better', Track::firstOrFail()->name);

        $component->call('startEdit', File::firstOrFail()->id)->assertSet('state.can_undo', true)->assertSee('data-test="undo-edit"', false)
            ->call('undoEdit')->assertSet('tags.title', 'Raw 1');
        $this->assertSame('Raw 1', Track::firstOrFail()->name);
    }

    public function test_the_file_dialog_changes_and_removes_the_cover(): void
    {
        $this->actingAs($this->admin());
        $this->album(1);
        $file = File::firstOrFail();

        Livewire::test(StorageFiles::class)->call('startEdit', $file->id)
            ->set('cover', UploadedFile::fake()->image('c.png', 200, 200))->call('saveTags')->assertSet('editing', null);
        $this->assertNotEmpty(AudioTags::read($this->base.'/library/'.File::firstOrFail()->path)['picture']['data']);

        Livewire::test(StorageFiles::class)->call('startEdit', File::firstOrFail()->id)->call('removeCover')->assertSet('state.has_cover', false);
        $this->assertNull(AudioTags::read($this->base.'/library/'.File::firstOrFail()->path)['picture']);
    }

    public function test_an_inbox_file_opens_read_only_and_nothing_is_written(): void
    {
        $this->actingAs($this->admin());
        $inbox = $this->location('inbox', 'inbox');
        $path = $this->makeAudio('inbox', 'x.mp3', ['title' => 'Inbox', 'artist' => 'Someone']);
        $file = File::create(['storage_location_id' => $inbox->id, 'path' => 'x.mp3', 'hash' => hash_file('sha256', $path), 'size' => filesize($path), 'mtime' => 1, 'extension' => 'mp3', 'status' => 'present']);
        $before = hash_file('sha256', $path);

        Livewire::test(StorageFiles::class)->set('locationId', (string) $inbox->id)->call('startEdit', $file->id)
            ->assertSet('state.editable', false)->assertSee('data-test="not-editable"', false)
            ->set('tags.title', 'Changed')->call('saveTags');

        $this->assertSame($before, hash_file('sha256', $path));
    }
}
