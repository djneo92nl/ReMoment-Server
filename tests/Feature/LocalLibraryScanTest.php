<?php

namespace Tests\Feature;

use App\Domain\Storage\LibraryFiles;
use App\Models\File;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Services\Local\LocalLibraryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File as Fs;
use Tests\TestCase;

class LocalLibraryScanTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/remoment-files-'.uniqid();
        Fs::makeDirectory($this->base.'/library', 0777, true);
        Fs::makeDirectory($this->base.'/inbox', 0777, true);
    }

    protected function tearDown(): void
    {
        Fs::deleteDirectory($this->base);

        parent::tearDown();
    }

    private function location(string $name, string $role): StorageLocation
    {
        return StorageLocation::create(['name' => $name, 'driver' => 'local', 'role' => $role, 'config' => ['root' => $this->base.'/'.$name]]);
    }

    /** One second of 8-bit mono silence; $seed makes the content (and so the hash) differ. */
    private function wav(int $seed = 0): string
    {
        $data = str_repeat(chr(0x80 + $seed), 8000);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8).'data'.pack('V', strlen($data)).$data;
    }

    /** About 1 s of MPEG-1 layer 3 128 kbps silence frames (enough for getID3 to see audio and write tags). */
    private function mp3(): string
    {
        return str_repeat("\xFF\xFB\x90\x00".str_repeat("\0", 413), 40);
    }

    public function test_scan_indexes_a_file_and_skips_it_when_unchanged(): void
    {
        $library = $this->location('library', 'library');
        Fs::makeDirectory($this->base.'/library/sub');
        file_put_contents($this->base.'/library/sub/one.wav', $this->wav());

        $scanner = app(LocalLibraryScanner::class);
        $this->assertSame(1, $scanner->scan($library));

        $track = Track::firstOrFail();
        $this->assertSame('one', $track->name);
        $this->assertSame(1, $track->duration);
        $this->assertSame(\App\Domain\Storage\StreamUrl::for($track->id), $track->getDlnaUrl());
        $this->assertSame(1, File::count());
        $this->assertTrue(TrackFile::firstOrFail()->is_primary);

        $this->assertSame(0, $scanner->scan($library));
        $this->assertSame(1, Track::count());
    }

    public function test_a_moved_file_keeps_its_file_row_and_track(): void
    {
        $library = $this->location('library', 'library');
        file_put_contents($this->base.'/library/a.wav', $this->wav());
        $scanner = app(LocalLibraryScanner::class);
        $scanner->scan($library);
        $fileId = File::firstOrFail()->id;
        $trackId = Track::firstOrFail()->id;

        Fs::makeDirectory($this->base.'/library/new');
        rename($this->base.'/library/a.wav', $this->base.'/library/new/b.wav');
        $scanner->scan($library);

        $this->assertSame(1, File::count());
        $this->assertSame('new/b.wav', File::findOrFail($fileId)->path);
        $this->assertSame('present', File::findOrFail($fileId)->status);
        $this->assertSame([$trackId], Track::pluck('id')->all());
    }

    public function test_a_vanished_file_is_marked_missing(): void
    {
        $library = $this->location('library', 'library');
        file_put_contents($this->base.'/library/a.wav', $this->wav());
        app(LocalLibraryScanner::class)->scan($library);

        unlink($this->base.'/library/a.wav');
        app(LocalLibraryScanner::class)->scan($library);

        $this->assertSame('missing', File::firstOrFail()->status);
        $this->get('/stream/'.Track::firstOrFail()->id)->assertNotFound();
    }

    public function test_stream_serves_the_file_with_range_support(): void
    {
        $library = $this->location('library', 'library');
        file_put_contents($this->base.'/library/one.wav', $this->wav());
        app(LocalLibraryScanner::class)->scan($library);
        $track = Track::firstOrFail();

        $this->get('/stream/'.$track->id)->assertOk()->assertHeader('Accept-Ranges', 'bytes');
        $this->get('/stream/'.$track->id, ['Range' => 'bytes=0-9'])
            ->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-9/'.(44 + 8000));
    }

    public function test_stream_prefers_the_requested_proxy_and_falls_back_to_the_original(): void
    {
        $library = $this->location('library', 'library');
        file_put_contents($this->base.'/library/one.wav', $this->wav(0));
        file_put_contents($this->base.'/library/one-proxy.wav', $this->wav(1));
        app(LocalLibraryScanner::class)->scan($library);

        $original = File::where('path', 'one.wav')->firstOrFail();
        $proxy = File::where('path', 'one-proxy.wav')->firstOrFail();
        $track = $original->tracks()->firstOrFail();
        TrackFile::where('file_id', $proxy->id)->delete();
        TrackFile::create(['track_id' => $track->id, 'file_id' => $proxy->id, 'role' => 'proxy', 'profile' => 'low', 'derived_from_file_id' => $original->id]);

        $originalBytes = file_get_contents($this->base.'/library/one.wav');
        $proxyBytes = file_get_contents($this->base.'/library/one-proxy.wav');

        $this->assertSame($originalBytes, $this->get('/stream/'.$track->id)->baseResponse->getFile()->getContent());
        $this->assertSame($proxyBytes, $this->get('/stream/'.$track->id.'?profile=low')->baseResponse->getFile()->getContent());
        $this->assertSame($originalBytes, $this->get('/stream/'.$track->id.'?profile=none')->baseResponse->getFile()->getContent());
    }

    public function test_stream_is_404_for_a_track_without_a_file(): void
    {
        $this->get('/stream/999')->assertNotFound();
    }

    public function test_upload_lands_in_the_library_layout(): void
    {
        $library = $this->location('library', 'library');
        $this->location('inbox', 'inbox');

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $this->wav());
        rewind($stream);

        $file = app(LibraryFiles::class)->upload('Some Song.wav', $stream);

        $this->assertSame($library->id, $file->storage_location_id);
        $this->assertSame('Unknown Artist/Unknown Album/Some Song.wav', $file->path);
        $this->assertFileExists($this->base.'/library/'.$file->path);
        $this->assertSame([], glob($this->base.'/inbox/_uploads/*/*') ?: []);
    }

    public function test_import_inbox_moves_files_and_keeps_collisions_apart(): void
    {
        $this->location('library', 'library');
        $inbox = $this->location('inbox', 'inbox');
        file_put_contents($this->base.'/inbox/x.wav', $this->wav(0));
        file_put_contents($this->base.'/inbox/y.wav', $this->wav(1));

        $this->assertSame(2, app(LibraryFiles::class)->importInbox());

        $this->assertSame(0, $inbox->files()->count());
        $this->assertEqualsCanonicalizing(
            ['Unknown Artist/Unknown Album/x.wav', 'Unknown Artist/Unknown Album/y.wav'],
            File::pluck('path')->all(),
        );
    }

    public function test_import_inbox_can_keep_the_originals_and_skips_them_next_time(): void
    {
        $library = $this->location('library', 'library');
        $inbox = $this->location('inbox', 'inbox');
        file_put_contents($this->base.'/inbox/x.wav', $this->wav(0));

        $this->assertSame(1, app(LibraryFiles::class)->importInbox(keepSource: true));

        $this->assertFileExists($this->base.'/inbox/x.wav');
        $this->assertFileExists($this->base.'/library/Unknown Artist/Unknown Album/x.wav');
        $this->assertSame(1, $library->files()->count());
        $this->assertSame(0, $inbox->files()->count());

        $this->assertSame(0, app(LibraryFiles::class)->importInbox(keepSource: true));
        $this->assertSame(1, File::count());
    }

    public function test_delete_track_removes_files_and_the_track(): void
    {
        $library = $this->location('library', 'library');
        file_put_contents($this->base.'/library/a.wav', $this->wav());
        app(LocalLibraryScanner::class)->scan($library);

        app(LibraryFiles::class)->deleteTrack(Track::firstOrFail());

        $this->assertFileDoesNotExist($this->base.'/library/a.wav');
        $this->assertSame(0, File::count());
        $this->assertSame(0, Track::count());
    }

    public function test_move_renames_a_file(): void
    {
        $library = $this->location('library', 'library');
        file_put_contents($this->base.'/library/a.wav', $this->wav());
        app(LocalLibraryScanner::class)->scan($library);

        $file = app(LibraryFiles::class)->move(File::firstOrFail(), 'moved/b.wav');

        $this->assertSame('moved/b.wav', $file->path);
        $this->assertFileExists($this->base.'/library/moved/b.wav');
        $this->assertFileDoesNotExist($this->base.'/library/a.wav');
        $this->assertSame(1, Track::count());
    }

    public function test_write_tags_round_trip_retags_and_refiles_the_track(): void
    {
        $library = $this->location('library', 'library');
        file_put_contents($this->base.'/library/a.mp3', $this->mp3());
        app(LocalLibraryScanner::class)->scan($library);
        $this->assertSame('a', Track::firstOrFail()->name);

        $file = app(LibraryFiles::class)->writeTags(File::firstOrFail(), [
            'title' => 'Real Title', 'artist' => 'Real Artist', 'album' => 'Real Album', 'year' => '1999', 'track_number' => 3,
        ]);

        $track = Track::with('artist', 'album')->firstOrFail();
        $this->assertSame(1, Track::count());
        $this->assertSame('Real Title', $track->name);
        $this->assertSame('Real Artist', $track->artist->name);
        $this->assertSame('Real Album', $track->album->name);
        $this->assertSame('Real Artist/Real Album (1999)/03 Real Title.mp3', $file->path);
        $this->assertFileExists($this->base.'/library/'.$file->path);
        $this->assertFileDoesNotExist($this->base.'/library/a.mp3');
    }

    public function test_duplicates_are_found_by_hash(): void
    {
        $library = $this->location('library', 'library');
        file_put_contents($this->base.'/library/a.wav', $this->wav());
        file_put_contents($this->base.'/library/b.wav', $this->wav());
        app(LocalLibraryScanner::class)->scan($library);

        $groups = app(LibraryFiles::class)->duplicates();

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups[0]);
    }

    public function test_the_stream_url_uses_the_configured_base_when_played(): void
    {
        $library = $this->location('library', 'library');
        file_put_contents($this->base.'/library/a.wav', $this->wav());
        app(LocalLibraryScanner::class)->scan($library);
        $track = Track::firstOrFail();

        config(['app.url' => 'http://remoment.test', 'app.stream_url' => null]);
        $this->assertSame('http://remoment.test/stream/'.$track->id, $track->getDlnaUrl());

        config(['app.stream_url' => 'http://192.168.1.48:8080/']);
        $this->assertSame('http://192.168.1.48:8080/stream/'.$track->id, Track::find($track->id)->getDlnaUrl());
    }

    public function test_the_stream_answers_without_a_session_or_cookies(): void
    {
        $library = $this->location('library', 'library');
        file_put_contents($this->base.'/library/a.wav', $this->wav());
        app(LocalLibraryScanner::class)->scan($library);

        $response = $this->get('/stream/'.Track::firstOrFail()->id)->assertOk();

        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($response->headers->get('Set-Cookie'));
    }

    public function test_our_stream_urls_are_found_again_whatever_host_they_were_built_with(): void
    {
        $library = $this->location('library', 'library');
        file_put_contents($this->base.'/library/a.wav', $this->wav());
        app(LocalLibraryScanner::class)->scan($library);
        $track = Track::firstOrFail();

        $this->assertSame($track->id, \App\Domain\Library\DlnaTrackLookup::byUrl('http://192.168.1.48:8080/stream/'.$track->id)?->id);
        $this->assertSame($track->id, \App\Domain\Library\DlnaTrackLookup::byUrl('http://remoment.test/stream/'.$track->id.'?x=1')?->id);
        $this->assertNull(\App\Domain\Storage\StreamUrl::trackId('http://x/other/5'));
    }
}
