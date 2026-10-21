<?php

namespace Tests\Feature;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LiteArtwork;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Library\Enrichment;
use App\Domain\Storage\FileIndexer;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Services\MqttService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Tiny GIF/BMP/PGM covers for old clients, cut on demand from the processed 320 px cover. */
class LiteArtworkTest extends TestCase
{
    use RefreshDatabase;

    private const COVER = 'https://img.test/cover.jpg';

    private string $hash;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->hash = md5(self::COVER);

        // A 320 px cover: left half white, right half blue.
        $image = imagecreatetruecolor(320, 320);
        imagefilledrectangle($image, 0, 0, 159, 319, 0xFFFFFF);
        imagefilledrectangle($image, 160, 0, 319, 319, 0x0000FF);
        ob_start();
        imagejpeg($image);
        Storage::disk('public')->put("artwork/{$this->hash}/320.jpg", ob_get_clean());
    }

    public function test_gif_has_the_size_and_palette_asked_for(): void
    {
        $response = $this->get("/api/artwork/{$this->hash}/64.gif?colors=4");

        $response->assertOk();
        $this->assertSame('image/gif', $response->headers->get('Content-Type'));
        $image = imagecreatefromstring($response->getContent());
        $this->assertSame([64, 64], [imagesx($image), imagesy($image)]);
        $this->assertLessThanOrEqual(4, imagecolorstotal($image));
        Storage::disk('public')->assertExists("artwork/{$this->hash}/lite/64-4.gif");
    }

    public function test_bmp_is_an_8_bit_palette_image(): void
    {
        $response = $this->get("/api/artwork/{$this->hash}/32.bmp");

        $response->assertOk();
        $bytes = $response->getContent();
        $this->assertSame('BM', substr($bytes, 0, 2));
        $this->assertSame(8, unpack('v', substr($bytes, 28, 2))[1]); // bits per pixel in the DIB header
        $this->assertSame([32, 32], [unpack('V', substr($bytes, 18, 4))[1], abs(unpack('l', substr($bytes, 22, 4))[1])]);
    }

    public function test_pgm_is_binary_grey_with_the_asked_number_of_levels(): void
    {
        $bytes = $this->get("/api/artwork/{$this->hash}/32.pgm?colors=2")->getContent();

        $this->assertStringStartsWith("P5\n32 32\n255\n", $bytes);
        $pixels = substr($bytes, strlen("P5\n32 32\n255\n"));
        $this->assertSame(32 * 32, strlen($pixels));
        $this->assertEqualsCanonicalizing([0, 255], array_values(array_unique(array_map('ord', str_split($pixels)))));
    }

    public function test_unknown_sizes_colors_and_unprocessed_covers_are_refused(): void
    {
        $this->get("/api/artwork/{$this->hash}/77.gif")->assertNotFound();
        $this->get("/api/artwork/{$this->hash}/64.gif?colors=7")->assertStatus(422);
        $this->get('/api/artwork/'.md5('nothing').'/64.gif')->assertNotFound();
        $this->get("/api/artwork/{$this->hash}/64.png")->assertNotFound();
        $this->assertContains(64, LiteArtwork::SIZES);
    }

    public function test_play_command_points_at_the_lite_artwork_once_the_cover_is_processed(): void
    {
        $artist = Artist::create(['name' => 'A', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'B', 'source' => 'dlna', 'images' => [['url' => self::COVER]]]);
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'external_id' => 'f:1', 'name' => 'T', 'duration' => 100, 'source' => 'dlna']);
        Enrichment::save($track, 'dlna_url', 'x', 'url', FileIndexer::URL_SOURCE);

        $id = $this->postJson('/api/players/register', ['name' => 'Palm', 'kind' => 'retro'])->json('device_id');
        DeviceCache::updateState($id, State::Standby);

        ArtworkCache::put(self::COVER, array_fill_keys(ArtworkCache::REQUIRED_KEYS, '/storage/artwork/x.jpg'));
        Device::find($id)->driver->playLibraryTracks(collect([$track]));

        $play = json_decode(collect(app(MqttService::class)->published)->firstWhere('topic', "remoment/playercmd/{$id}")['message'], true);
        $this->assertSame("/api/artwork/{$this->hash}", $play['track']['lite']);
    }
}
