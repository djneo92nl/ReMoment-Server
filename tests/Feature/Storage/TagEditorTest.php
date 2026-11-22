<?php

namespace Tests\Feature\Storage;

use App\Domain\Storage\AudioTags;
use App\Domain\Storage\LibraryFiles;
use App\Domain\Storage\TagEditor;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\FileTagSnapshot;
use App\Models\Media\Album;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MakesTaggedFiles;
use Tests\TestCase;

/** The tag editor for own files (workstream 8.4, docs/architecture/tag-editor.md). */
class TagEditorTest extends TestCase
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
        // No network and no workers: enrichment and artwork jobs are only recorded.
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->removeBase();
        parent::tearDown();
    }

    private function editor(): TagEditor
    {
        return app(TagEditor::class);
    }

    /** @return array{0: File, 1: string} an indexed file with a cover and extra tags */
    private function libraryFile(array $tags = [], bool $cover = true, string $path = 'in/a.mp3'): array
    {
        $library = $this->location('library', 'library');
        $this->makeAudio('library', $path, $tags + [
            'title' => 'Old Title', 'artist' => 'Old Artist', 'album' => 'Old Album', 'date' => '1990', 'track' => '1',
            'MusicBrainz Album Id' => '11111111-2222-3333-4444-555555555555',
        ], $cover ? $this->jpeg() : null);
        $this->scan($library);

        return [File::firstOrFail(), $this->base.'/library/'.$path];
    }

    private function read(string $path): array
    {
        return AudioTags::read($path);
    }

    public function test_an_edit_changes_the_tags_and_keeps_the_cover_and_the_other_tags(): void
    {
        [$file, $path] = $this->libraryFile();

        $file = $this->editor()->edit($file, ['title' => 'New Title', 'year' => '2001', 'genre' => 'Jazz'], organize: false);

        $read = $this->read($this->base.'/library/'.$file->path);
        $this->assertSame('New Title', $read['title']);
        $this->assertSame('Old Artist', $read['artist']);
        $this->assertSame('2001', $read['year']);
        $this->assertSame(['Jazz'], $read['genres']);
        $this->assertNotEmpty($read['picture']['data'], 'the embedded cover survives');
        $this->assertSame('11111111-2222-3333-4444-555555555555', $read['release_mbid'], 'frames that were not edited survive');
        $this->assertSame('New Title', Track::firstOrFail()->name);
    }

    public function test_the_index_stays_consistent_hash_size_and_one_track(): void
    {
        [$file] = $this->libraryFile();
        $oldHash = $file->hash;

        $file = $this->editor()->edit($file, ['title' => 'Other'], organize: false);

        $disk = $this->base.'/library/'.$file->path;
        $this->assertNotSame($oldHash, $file->hash);
        $this->assertSame(hash_file('sha256', $disk), $file->hash);
        $this->assertSame(filesize($disk), $file->size);
        $this->assertSame(1, File::count());
        $this->assertSame(1, Track::count());
        $this->assertSame('present', $file->status);
        $this->assertSame([], glob($this->base.'/library/in/.*.edit-*') ?: [], 'no temp file is left behind');
    }

    public function test_the_analysis_of_the_audio_follows_the_file_to_its_new_hash(): void
    {
        [$file] = $this->libraryFile();
        FileAnalysis::create(['file_id' => $file->id, 'content_hash' => $file->hash, 'bpm' => 120]);

        $file = $this->editor()->edit($file, ['title' => 'Other'], organize: false);

        $this->assertSame($file->hash, FileAnalysis::where('file_id', $file->id)->value('content_hash'));
    }

    public function test_an_empty_value_removes_a_field_and_a_missing_one_leaves_it(): void
    {
        [$file] = $this->libraryFile(['genre' => 'Rock']);

        $file = $this->editor()->edit($file, ['genre' => '', 'album' => null], organize: false);

        $read = $this->read($this->base.'/library/'.$file->path);
        $this->assertSame([], $read['genres']);
        $this->assertSame('Old Album', $read['album']);
    }

    public function test_organize_moves_the_file_to_the_layout_for_its_new_tags(): void
    {
        [$file] = $this->libraryFile();

        $file = $this->editor()->edit($file, ['title' => 'Song', 'artist' => 'Band', 'album' => 'LP', 'year' => '1999', 'track_number' => 2]);

        $this->assertSame('Band/LP (1999)/02 Song.mp3', $file->path);
        $this->assertFileExists($this->base.'/library/Band/LP (1999)/02 Song.mp3');
        $this->assertFileDoesNotExist($this->base.'/library/in/a.mp3');
    }

    public function test_a_failed_write_leaves_the_file_exactly_as_it_was(): void
    {
        [$file, $path] = $this->libraryFile();
        $before = hash_file('sha256', $path);

        Process::fake(['*' => Process::result(errorOutput: 'boom', exitCode: 1)]);

        try {
            $this->editor()->edit($file, ['title' => 'Never']);
            $this->fail('the write should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ffmpeg could not write the tags', $e->getMessage());
        }

        $this->assertSame($before, hash_file('sha256', $path));
        $this->assertSame($before, $file->fresh()->hash);
        $this->assertSame('Old Title', Track::firstOrFail()->name);
        $this->assertSame([], glob($this->base.'/library/in/.*.edit-*') ?: []);
        $this->assertDatabaseCount('file_tag_snapshots', 0);
    }

    public function test_a_result_that_does_not_hold_the_tags_is_refused_and_the_original_kept(): void
    {
        [$file, $path] = $this->libraryFile();
        $before = hash_file('sha256', $path);

        // ffmpeg "succeeds" but wrote a copy without the new title.
        Process::fake(fn ($process) => tap(Process::result(), fn () => copy($path, end($process->command))));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not hold the title');

        try {
            $this->editor()->edit($file, ['title' => 'Asked For']);
        } finally {
            $this->assertSame($before, hash_file('sha256', $path));
        }
    }

    public function test_files_in_the_inbox_are_never_touched(): void
    {
        $inbox = $this->location('inbox', 'inbox');
        $path = $this->makeAudio('inbox', 'x.mp3', ['title' => 'Inbox Song', 'artist' => 'Someone']);
        $file = File::create(['storage_location_id' => $inbox->id, 'path' => 'x.mp3', 'hash' => hash_file('sha256', $path), 'size' => filesize($path), 'mtime' => 1, 'extension' => 'mp3', 'status' => 'present']);
        $before = hash_file('sha256', $path);

        try {
            $this->editor()->edit($file, ['title' => 'Changed']);
            $this->fail('an inbox file must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('inbox', $e->getMessage());
        }

        $this->assertSame($before, hash_file('sha256', $path));
    }

    public function test_input_is_validated(): void
    {
        [$file] = $this->libraryFile();

        foreach ([['year' => 'soon'], ['track_number' => 'x'], []] as $bad) {
            try {
                $this->editor()->edit($file, $bad);
                $this->fail('should have been refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_undo_puts_back_tags_cover_and_path(): void
    {
        [$file, $path] = $this->libraryFile(['genre' => 'Rock']);
        $original = $this->read($path);

        $file = $this->editor()->edit($file, ['title' => 'Song', 'artist' => 'Band', 'album' => 'LP', 'genre' => ''], ['data' => $this->jpeg(0x2222CC), 'mime' => 'image/jpeg']);
        $this->assertNotSame('in/a.mp3', $file->path);
        $this->assertTrue($this->editor()->canUndo($file));

        $file = $this->editor()->undo($file);

        $this->assertSame('in/a.mp3', $file->path);
        $restored = $this->read($this->base.'/library/in/a.mp3');
        $this->assertSame('Old Title', $restored['title']);
        $this->assertSame('Old Artist', $restored['artist']);
        $this->assertSame(['Rock'], $restored['genres']);
        $this->assertSame(md5($original['picture']['data']), md5($restored['picture']['data']), 'the previous cover is back');
        $this->assertSame('Old Title', Track::firstOrFail()->name);
        $this->assertFalse($this->editor()->canUndo($file));
        $this->assertDatabaseCount('file_tag_snapshots', 0);
    }

    public function test_undo_of_an_added_cover_removes_it_and_a_second_edit_replaces_the_snapshot(): void
    {
        [$file] = $this->libraryFile(cover: false);

        $file = $this->editor()->edit($file, [], ['data' => $this->jpeg(), 'mime' => 'image/jpeg'], organize: false);
        $this->assertNotEmpty($this->read($this->base.'/library/'.$file->path)['picture']['data']);

        $file = $this->editor()->undo($file);
        $this->assertNull($this->read($this->base.'/library/'.$file->path)['picture']);

        $file = $this->editor()->edit($file, ['title' => 'One'], organize: false);
        $file = $this->editor()->edit($file, ['title' => 'Two'], organize: false);
        $file = $this->editor()->undo($file);
        $this->assertSame('One', $this->read($this->base.'/library/'.$file->path)['title'], 'only the last edit is undone');
    }

    public function test_undo_is_refused_once_the_file_changed_again(): void
    {
        [$file] = $this->libraryFile();
        $file = $this->editor()->edit($file, ['title' => 'Song'], organize: false);

        $file->update(['hash' => str_repeat('a', 64)]); // a scan found other bytes

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('changed after its last edit');
        $this->editor()->undo($file);
    }

    public function test_a_bad_cover_is_refused(): void
    {
        [$file] = $this->libraryFile();

        foreach (['not an image', str_repeat('x', 20)] as $bytes) {
            try {
                $this->editor()->edit($file, [], ['data' => $bytes, 'mime' => null]);
                $this->fail('refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_cover_replaces_the_embedded_one_and_flac_works_too(): void
    {
        $library = $this->location('library', 'library');
        $this->makeAudio('library', 'f.flac', ['title' => 'Flac Song', 'artist' => 'Band', 'album' => 'LP'], $this->jpeg(), 'flac');
        $this->scan($library);
        $file = File::firstOrFail();

        $file = $this->editor()->edit($file, ['title' => 'Renamed', 'track_number' => 4, 'album_artist' => 'Band'], ['data' => $this->jpeg(0x00AA00), 'mime' => 'image/jpeg'], organize: false);

        $read = $this->read($this->base.'/library/'.$file->path);
        $this->assertSame('Renamed', $read['title']);
        $this->assertSame(4, $read['track_number']);
        $this->assertSame('Band', $read['album_artist']);
        $this->assertNotEmpty($read['picture']['data']);
    }

    public function test_the_legacy_write_path_goes_through_the_editor(): void
    {
        [$file] = $this->libraryFile();

        $file = app(LibraryFiles::class)->writeTags($file, ['title' => 'Via Library Files', 'genre' => '']);

        $this->assertSame('Via Library Files', Track::firstOrFail()->name);
        $this->assertDatabaseCount('file_tag_snapshots', 1);
        $this->assertNotEmpty($this->read($this->base.'/library/'.$file->path)['picture']['data'], 'the cover is no longer lost by a retag');
    }

    public function test_an_album_edit_changes_every_file_skips_the_inbox_and_matches_the_library(): void
    {
        $library = $this->location('library', 'library');
        foreach ([1, 2, 3] as $n) {
            $this->makeAudio('library', "raw/{$n}.mp3", ['title' => "Raw {$n}", 'artist' => 'Band', 'album' => 'Raw Album', 'track' => (string) $n], $n === 1 ? $this->jpeg() : null);
        }
        $this->scan($library);
        $album = Album::firstOrFail();
        $this->assertSame(3, Track::where('album_id', $album->id)->count());

        $files = File::orderBy('path')->get();
        $result = $this->editor()->editAlbum($album, ['album' => 'Fixed Album', 'year' => '2005', 'genre' => 'Pop'], [
            $files[0]->id => ['title' => 'One'], $files[1]->id => ['title' => 'Two'], $files[2]->id => ['title' => 'Three'],
        ]);

        $this->assertCount(3, $result['edited']);
        $this->assertSame([], $result['failed']);
        $newAlbum = Album::findOrFail($result['album_id']);
        $this->assertSame('Fixed Album', $newAlbum->name);
        $this->assertSame(1, Album::count(), 'the old album is gone, tracks moved to the new one');
        $this->assertEqualsCanonicalizing(['One', 'Two', 'Three'], Track::where('album_id', $newAlbum->id)->pluck('name')->all());
        $this->assertSame(3, Track::count());
        foreach (File::all() as $file) {
            $this->assertSame(hash_file('sha256', $this->base.'/library/'.$file->path), $file->hash);
            $this->assertStringStartsWith('Band/Fixed Album (2005)/', $file->path);
        }
    }

    public function test_an_album_cover_is_embedded_everywhere_and_becomes_the_album_picture(): void
    {
        $library = $this->location('library', 'library');
        foreach ([1, 2] as $n) {
            $this->makeAudio('library', "a/{$n}.mp3", ['title' => "T{$n}", 'artist' => 'Band', 'album' => 'LP']);
        }
        $this->scan($library);
        $cover = $this->editor()->coverFrom($this->jpeg(0x3366CC));

        $result = $this->editor()->editAlbum(Album::firstOrFail(), cover: $cover, organize: false);

        $this->assertCount(2, $result['edited']);
        foreach (File::all() as $file) {
            $this->assertSame(sha1($cover['data']) !== '' ? md5($cover['data']) : '', md5($this->read($this->base.'/library/'.$file->path)['picture']['data']));
        }
        $this->assertStringStartsWith('remoment:cover/', Album::firstOrFail()->images[0]['url']);
    }

    public function test_album_undo_reverts_every_file_and_names_the_ones_that_cannot_be_edited(): void
    {
        $library = $this->location('library', 'library');
        $inbox = $this->location('inbox', 'inbox');
        $this->makeAudio('library', 'a/1.mp3', ['title' => 'T1', 'artist' => 'Band', 'album' => 'LP']);
        $this->makeAudio('library', 'a/2.mp3', ['title' => 'T2', 'artist' => 'Band', 'album' => 'LP']);
        $this->scan($library);
        $album = Album::firstOrFail();

        // A third file of the album sits in the inbox (the user's own music): never edited.
        $path = $this->makeAudio('inbox', 'z.mp3', ['title' => 'T3']);
        $third = File::create(['storage_location_id' => $inbox->id, 'path' => 'z.mp3', 'hash' => hash_file('sha256', $path), 'size' => filesize($path), 'mtime' => 1, 'extension' => 'mp3', 'status' => 'present']);
        \App\Models\TrackFile::create(['track_id' => Track::where('album_id', $album->id)->first()->id, 'file_id' => $third->id, 'role' => 'original', 'is_primary' => false]);

        $result = $this->editor()->editAlbum($album, ['album' => 'Changed'], organize: false);
        $this->assertCount(2, $result['edited']);
        $this->assertSame([['file_id' => $third->id, 'reason' => 'inbox']], $result['skipped']);

        $changed = Album::findOrFail($result['album_id']);
        $this->assertTrue($this->editor()->albumCanUndo($changed));
        $undo = $this->editor()->undoAlbum($changed);

        $this->assertCount(2, $undo['undone']);
        $this->assertSame(['LP'], Album::pluck('name')->unique()->values()->all());
        $this->assertSame('T3', AudioTags::read($path)['title']);
    }

    public function test_library_identity_and_merge_keep_working_after_a_tag_change(): void
    {
        $library = $this->location('library', 'library');
        $this->makeAudio('library', 'a/1.mp3', ['title' => 'Same Song', 'artist' => 'Band', 'album' => 'LP']);
        $this->makeAudio('library', 'b/1.mp3', ['title' => 'Same Song (live)', 'artist' => 'Band', 'album' => 'Other']);
        $this->scan($library);
        $this->assertSame(2, Track::count());

        // Retagging the second like the first lands both on the same album; the merge command still runs clean.
        $second = File::orderByDesc('id')->first();
        $this->editor()->edit($second, ['title' => 'Same Song', 'album' => 'LP'], organize: false);

        $this->assertSame(1, Album::count());
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('library:merge-duplicates', ['--dry-run' => true]));
        $this->assertSame(2, File::count());
    }

    public function test_read_reports_what_the_file_holds_and_what_can_be_done(): void
    {
        [$file] = $this->libraryFile(['genre' => 'Rock']);

        $state = $this->editor()->read($file);

        $this->assertSame('Old Title', $state['tags']['title']);
        $this->assertSame('Rock', $state['tags']['genre']);
        $this->assertSame('1', $state['tags']['track_number']);
        $this->assertTrue($state['has_cover']);
        $this->assertTrue($state['editable']);
        $this->assertTrue($state['cover_editable']);
        $this->assertFalse($state['can_undo']);
        $this->assertDatabaseCount('file_tag_snapshots', 0);
        $this->assertSame(0, FileTagSnapshot::count());
    }
}
