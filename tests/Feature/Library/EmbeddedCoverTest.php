<?php

namespace Tests\Feature\Library;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LocalCover;
use App\Domain\Storage\AudioTags;
use App\Domain\Storage\FileIndexer;
use App\Jobs\ProcessArtwork;
use App\Models\Media\Album;
use App\Models\StorageLocation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Covers embedded in the own audio files: read at index time, backfilled, processed like any cover. */
class EmbeddedCoverTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->dir = sys_get_temp_dir().'/remoment-cover-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function jpeg(int $side = 300, int $color = 0x3366CC): string
    {
        $image = imagecreatetruecolor($side, $side);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagejpeg($image);

        return ob_get_clean();
    }

    /** A tiny but valid mp3 (silent MPEG frames) with ID3v2.3 text tags and, optionally, an embedded front cover. */
    private function writeMp3(string $name, string $album, ?string $picture = null): string
    {
        $frame = fn (string $id, string $body) => $id.pack('N', strlen($body))."\x00\x00".$body;
        $frames = $frame('TIT2', "\x00".$name).$frame('TPE1', "\x00Band").$frame('TALB', "\x00".$album);
        if ($picture !== null) {
            $frames .= $frame('APIC', "\x00image/jpeg\x00\x03\x00".$picture);
        }

        $size = strlen($frames);
        $syncsafe = chr(($size >> 21) & 0x7F).chr(($size >> 14) & 0x7F).chr(($size >> 7) & 0x7F).chr($size & 0x7F);
        $audio = str_repeat("\xFF\xFB\x90\x64".str_repeat("\x00", 413), 40);

        file_put_contents("{$this->dir}/{$name}.mp3", "ID3\x03\x00\x00".$syncsafe.$frames.$audio);

        return "{$name}.mp3";
    }

    private function location(): StorageLocation
    {
        return StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => $this->dir], 'role' => 'library']);
    }

    public function test_audio_tags_return_the_embedded_picture(): void
    {
        $jpeg = $this->jpeg();
        $this->writeMp3('song', 'LP', $jpeg);
        $this->writeMp3('plain', 'LP');

        $tags = AudioTags::read("{$this->dir}/song.mp3");
        $this->assertSame($jpeg, $tags['picture']['data']);
        $this->assertSame('image/jpeg', $tags['picture']['mime']);
        $this->assertSame('Band', $tags['artist']);

        $this->assertNull(AudioTags::read("{$this->dir}/plain.mp3")['picture']);
    }

    public function test_indexing_a_file_gives_its_album_the_cover_and_queues_processing(): void
    {
        Queue::fake();
        $location = $this->location();
        $jpeg = $this->jpeg();
        $path = $this->writeMp3('song', 'LP', $jpeg);

        app(FileIndexer::class)->index($location, $path);

        $album = Album::firstWhere('name', 'LP');
        $url = 'remoment:cover/'.sha1($jpeg);
        $this->assertSame([['url' => $url]], $album->images);
        Storage::disk('local')->assertExists('covers/'.sha1($jpeg));
        Queue::assertPushed(ProcessArtwork::class, fn ($job) => $job->originalUrl === $url);
        $this->assertSame($jpeg, LocalCover::read($url));
    }

    public function test_an_album_that_has_an_image_keeps_it(): void
    {
        Queue::fake();
        $location = $this->location();
        $first = $this->writeMp3('one', 'LP');
        app(FileIndexer::class)->index($location, $first);
        Album::firstWhere('name', 'LP')->update(['images' => [['url' => 'https://example.test/cover.jpg']]]);

        app(FileIndexer::class)->index($location, $this->writeMp3('two', 'LP', $this->jpeg()));

        $this->assertSame('https://example.test/cover.jpg', Album::firstWhere('name', 'LP')->images[0]['url']);
        Queue::assertNothingPushed();
    }

    public function test_the_backfill_reads_covers_of_albums_indexed_without_them_and_remembers_the_rest(): void
    {
        Queue::fake();
        $location = $this->location();
        $indexer = app(FileIndexer::class);
        $indexer->index($location, $this->writeMp3('a', 'With Art'));
        $indexer->index($location, $this->writeMp3('b', 'No Art'));
        $this->assertNull(Album::firstWhere('name', 'With Art')->images);

        // The file gains its picture later (retagged outside the app), without the indexer noticing.
        $jpeg = $this->jpeg();
        $this->writeMp3('a', 'With Art', $jpeg);

        $this->artisan('library:extract-covers')->expectsOutputToContain('Checked 2 album(s), found a cover for 1')->assertSuccessful();

        $this->assertSame([['url' => 'remoment:cover/'.sha1($jpeg)]], Album::firstWhere('name', 'With Art')->images);
        $this->assertNull(Album::firstWhere('name', 'No Art')->images);
        Queue::assertPushed(ProcessArtwork::class, 1);

        $this->artisan('library:extract-covers')->expectsOutputToContain('No albums without an image are left')->assertSuccessful();
    }

    public function test_only_real_covers_are_stored(): void
    {
        $this->assertNull(LocalCover::store(null));
        $this->assertNull(LocalCover::store('not an image'));
        $this->assertNull(LocalCover::store($this->jpeg(40)), 'a thumbnail is no cover');
        $this->assertNull(LocalCover::read('remoment:cover/../../.env'));
        $this->assertNull(LocalCover::read('https://example.test/cover.jpg'));
        $this->assertSame('remoment:cover/'.sha1($this->jpeg()), LocalCover::store($this->jpeg()));
    }

    public function test_the_artwork_job_processes_an_embedded_cover_from_disk(): void
    {
        $url = LocalCover::store($this->jpeg(400));

        ProcessArtwork::dispatchSync($url);

        $this->assertTrue(ArtworkCache::has($url));
        $album = Album::create(['artist_id' => \App\Models\Media\Artist::create(['name' => 'Band', 'source' => 'dlna'])->id, 'name' => 'LP', 'source' => 'dlna', 'images' => [['url' => $url]]]);
        ProcessArtwork::dispatchSync($url);
        $this->assertNotEmpty($album->fresh()->colors, 'the album gets its colours like with any cover');
    }
}
