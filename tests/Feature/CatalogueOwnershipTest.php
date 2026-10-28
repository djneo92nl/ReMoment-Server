<?php

namespace Tests\Feature;

use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\StorageLocation;
use App\Services\Local\LocalLibraryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File as Fs;
use Tests\TestCase;

/** An own file indexed by the scanner closes the wish on its release-group (docs/architecture/availability-layer.md). */
class CatalogueOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/remoment-catalogue-'.uniqid();
        Fs::makeDirectory($this->base.'/library', 0777, true);
    }

    protected function tearDown(): void
    {
        Fs::deleteDirectory($this->base);
        parent::tearDown();
    }

    public function test_indexing_a_file_of_a_wished_album_fulfils_the_wish(): void
    {
        // A tag-less file is filed under "Unknown Artist" / "Unknown Album".
        $artist = Artist::create(['name' => 'Unknown Artist', 'source' => 'files']);
        $row = CatalogueAlbum::create([
            'artist_id' => $artist->id, 'release_group_mbid' => 'rg-1', 'title' => 'Unknown Album', 'type' => 'album',
            'seen_at' => now(), 'wanted_at' => now(),
        ]);
        $data = str_repeat(chr(0x80), 8000);
        file_put_contents($this->base.'/library/one.wav', 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8).'data'.pack('V', strlen($data)).$data);
        $location = StorageLocation::create(['name' => 'library', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => $this->base.'/library']]);

        app(LocalLibraryScanner::class)->scan($location);

        $row->refresh();
        $this->assertNotNull($row->album_id);
        $this->assertNotNull($row->fulfilled_at);
    }
}
