<?php

namespace Tests\Feature;

use App\Domain\Catalogue\PhysicalItems;
use App\Domain\Catalogue\PhysicalRipLink;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\PhysicalItem;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File as Fs;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * A ripped disc and the CD on the shelf: a file with the release id or barcode of a CD in the physical collection marks the
 * item ripped and ties it to the album (docs/api/physical.md, ripper/README.md).
 */
class PhysicalRipLinkTest extends TestCase
{
    use RefreshDatabase;

    /** The MUSICBRAINZ_ALBUMID of tests/Fixtures/ripper-tagged.flac, a rip of "Fiction" by Dark Tranquillity. */
    private const RELEASE = 'a060c352-c9d6-4717-aab8-95a2965fa8d7';

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->base = sys_get_temp_dir().'/remoment-rip-'.uniqid();
        Fs::makeDirectory($this->base.'/library', 0777, true);
        StorageLocation::create(['name' => 'library', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => $this->base.'/library']]);
    }

    protected function tearDown(): void
    {
        Fs::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function file(string $path): File
    {
        return File::create(['storage_location_id' => StorageLocation::firstOrFail()->id, 'path' => $path, 'hash' => hash('sha256', $path), 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT]);
    }

    /** The upload the ripper makes: one tagged file to POST /api/admin/files with the admin token. */
    private function ripUpload(): void
    {
        RateLimiter::clear('admin-api-login|127.0.0.1');
        User::factory()->create();
        $token = $this->postJson('/api/admin/login', ['password' => 'password'])->json('token');
        $copy = $this->base.'/03 Terminus.flac';
        copy(__DIR__.'/../Fixtures/ripper-tagged.flac', $copy);

        $this->post('/api/admin/files', ['file' => new UploadedFile($copy, '03 Terminus.flac', 'audio/flac', null, true)],
            ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->assertCreated();
    }

    public function test_the_ripper_upload_marks_the_cd_with_that_release_ripped_and_links_the_album(): void
    {
        $cd = PhysicalItems::add(['artist' => 'Dark Tranquillity', 'title' => 'Fiction', 'medium' => 'cd', 'release_mbid' => self::RELEASE, 'location' => 'Shelf B2']);
        $vinyl = PhysicalItems::add(['artist' => 'Dark Tranquillity', 'title' => 'Fiction', 'medium' => 'vinyl', 'release_mbid' => self::RELEASE]);
        $other = PhysicalItems::add(['artist' => 'Someone', 'title' => 'Else', 'medium' => 'cd', 'release_mbid' => '99999999-9999-9999-9999-999999999999']);
        $this->assertNull($cd->ripped_at);

        $this->ripUpload();

        $album = Album::where('name', 'Fiction')->firstOrFail();
        $file = File::firstOrFail();
        $cd->refresh();
        $this->assertNotNull($cd->ripped_at);
        $this->assertSame([$album->id, $file->id], [$cd->album_id, $cd->file_id]);
        $this->assertNull($vinyl->fresh()->ripped_at, 'a vinyl is not ripped by a disc rip, whatever its release id');
        $this->assertNull($other->fresh()->ripped_at);

        $api = $this->getJson('/api/library/physical?ripped=1')->assertOk()->json('data');
        $this->assertSame([[$cd->id, true, $album->id]], array_map(fn ($i) => [$i['id'], $i['ripped'], $i['album_id']], $api));
    }

    public function test_the_collection_page_shows_ripped_with_a_link_to_the_album(): void
    {
        PhysicalItems::add(['artist' => 'Dark Tranquillity', 'title' => 'Fiction', 'medium' => 'cd', 'release_mbid' => self::RELEASE]);
        PhysicalItems::add(['artist' => 'Shelf', 'title' => 'Warmer', 'medium' => 'cd']);
        $this->ripUpload();
        $album = Album::where('name', 'Fiction')->firstOrFail();

        $this->get('/library/collection')->assertOk()->assertSee('Ripped')
            ->assertSee('data-test="ripped-link"', false)->assertSee(route('albums.show', $album->id), false);
        $this->assertSame(1, substr_count($this->get('/library/collection')->getContent(), 'data-test="ripped-link"'), 'only the ripped item links');
    }

    public function test_a_barcode_in_the_tags_matches_however_it_is_written(): void
    {
        $artist = Artist::create(['name' => 'Band']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'First Album', 'source' => 'files']);
        $file = $this->file('x.flac');
        $cd = PhysicalItems::add(['artist' => 'Band', 'title' => 'First Album (CD)', 'medium' => 'cd', 'barcode' => '0602 537-531159']);
        $this->assertSame('0602537531159', $cd->barcode, 'barcodes are stored as digits');

        $this->assertSame(1, PhysicalRipLink::link($file, $album, ['barcode' => '602537531159']), 'UPC-A against EAN-13');

        $this->assertNotNull($cd->fresh()->ripped_at);
        $this->assertSame($album->id, $cd->fresh()->album_id);
    }

    public function test_tags_without_a_release_or_barcode_link_nothing_and_a_second_file_is_not_a_second_link(): void
    {
        $artist = Artist::create(['name' => 'Band']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'First Album', 'source' => 'files']);
        $one = $this->file('1.flac');
        $two = $this->file('2.flac');
        $cd = PhysicalItems::add(['artist' => 'Band', 'title' => 'First Album', 'medium' => 'cd', 'release_mbid' => self::RELEASE]);

        $this->assertSame(0, PhysicalRipLink::link($one, $album, ['release_mbid' => null, 'barcode' => null]));
        $this->assertSame(0, PhysicalRipLink::link($one, $album, []));
        $this->assertNull($cd->fresh()->ripped_at);

        $this->assertSame(1, PhysicalRipLink::link($one, $album, ['release_mbid' => strtoupper(self::RELEASE)]), 'a release id in capitals');
        $stamp = $cd->fresh()->ripped_at;
        $this->assertSame(0, PhysicalRipLink::link($two, $album, ['release_mbid' => self::RELEASE]), 'the next track of the same disc');
        $this->assertEquals($stamp, $cd->fresh()->ripped_at);
        $this->assertSame($one->id, $cd->fresh()->file_id, 'the first file stays the item\'s file');
    }

    public function test_the_rip_takes_over_an_album_the_item_was_tied_to_by_name_and_keeps_the_wish_closed(): void
    {
        $artist = Artist::create(['name' => 'Band']);
        $byName = Album::create(['artist_id' => $artist->id, 'name' => 'First Album', 'source' => 'files']);
        $ripped = Album::create(['artist_id' => $artist->id, 'name' => 'First Album (Deluxe)', 'source' => 'files']);
        $file = $this->file('1.flac');
        $wish = CatalogueAlbum::create(['artist_id' => $artist->id, 'release_group_mbid' => '11111111-1111-1111-1111-111111111111', 'title' => 'First Album', 'type' => 'album', 'seen_at' => now(), 'wanted_at' => now()]);
        $cd = PhysicalItems::add(['artist' => 'Band', 'title' => 'First Album', 'medium' => 'cd', 'release_mbid' => self::RELEASE, 'release_group_mbid' => '11111111-1111-1111-1111-111111111111']);
        $this->assertSame($byName->id, $cd->album_id);

        PhysicalRipLink::link($file, $ripped, ['release_mbid' => self::RELEASE]);

        $this->assertSame($ripped->id, $cd->fresh()->album_id, 'the album the files landed in is the one that counts');
        $this->assertNotNull($wish->fresh()->fulfilled_at);
        $this->assertSame(1, PhysicalItem::count());
    }

    public function test_a_failure_in_the_link_never_stops_a_file_from_being_indexed(): void
    {
        $artist = Artist::create(['name' => 'Band']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'First Album', 'source' => 'files']);
        $file = $this->file('1.flac');
        \Illuminate\Support\Facades\Schema::drop('physical_items'); // the table the link reads is gone

        PhysicalRipLink::linkQuietly($file, $album, ['release_mbid' => self::RELEASE]);

        $this->addToAssertionCount(1);
    }
}
