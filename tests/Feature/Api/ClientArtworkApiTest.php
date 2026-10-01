<?php

namespace Tests\Feature\Api;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\SourceLogo;
use App\Http\Controllers\Api\ClientController;
use App\Models\Client;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** Contract tests for GET /api/clients/{api_token}/artwork (see docs/api/client-devices.md). */
class ClientArtworkApiTest extends TestCase
{
    use RefreshDatabase;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artist = Artist::create(['name' => 'A', 'source' => 'spotify']);

        // Rendering the eight logos takes a second or so; only the logo test needs real ones.
        foreach (SourceLogo::KEYS as $key) {
            $this->cacheProcessed(SourceLogo::url($key));
        }
    }

    private function token(): string
    {
        $client = Client::create([
            'status' => 'approved', 'type' => 'multi',
            'registration_token' => Client::generateToken(), 'api_token' => Client::generateToken(),
        ]);

        return $client->api_token;
    }

    private function album(string $name, ?array $images): Album
    {
        return Album::create(['artist_id' => $this->artist->id, 'name' => $name, 'source' => 'spotify', 'images' => $images]);
    }

    /** The album items of a response (the first page also lists the source logos). */
    private function albumItems($response): array
    {
        return collect($response->json('data'))->where('kind', 'album')->values()->all();
    }

    private function play(Album $album, string $at): void
    {
        $device = Device::firstOrCreate(['device_name' => 'Living Room'], [
            'ip_address' => '10.0.0.10', 'device_brand_name' => 'Test', 'device_product_type' => 'Speaker',
            'device_driver' => FakePlayerDriver::class, 'device_driver_name' => 'Fake',
        ]);
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $this->artist->id, 'name' => 'T', 'source' => 'spotify']);
        Play::create(['device_id' => $device->id, 'track_id' => $track->id, 'source_type' => 'spotify', 'played_at' => $at]);
    }

    private function item(string $url): array
    {
        $hash = md5($url);

        return [
            'kind' => 'album',
            'hash' => $hash,
            'proxy_320' => "/storage/artwork/{$hash}/320.jpg",
            'proxy_120' => "/storage/artwork/{$hash}/120.jpg",
            'proxy_bg' => "/storage/artwork/{$hash}/bg_1024x600.jpg",
            'proxy_bg_320x480' => "/storage/artwork/{$hash}/bg_320x480.jpg",
        ];
    }

    private function cacheProcessed(string $url): void
    {
        $hash = md5($url);
        ArtworkCache::put($url, [
            'proxy_512' => "/storage/artwork/{$hash}/512.jpg",
            'proxy_320' => "/storage/artwork/{$hash}/320.jpg",
            'proxy_120' => "/storage/artwork/{$hash}/120.jpg",
            'proxy_bg' => "/storage/artwork/{$hash}/bg_1024x600.jpg",
            'proxy_bg_320x480' => "/storage/artwork/{$hash}/bg_320x480.jpg",
            'colors' => ['#111111'],
            'safe_colors' => ['#aaaaaa'],
        ]);
    }

    public function test_it_lists_processed_album_artwork_by_hash(): void
    {
        $url = 'https://i.scdn.co/image/processed';
        $this->album('Processed', [['url' => $url, 'width' => 640]]);
        $this->album('Plain string image', ['https://x.test/plain.jpg']);
        $this->album('Not processed', [['url' => 'https://x.test/unprocessed.jpg']]);
        $this->album('No images', null);
        $this->cacheProcessed($url);
        $this->cacheProcessed('https://x.test/plain.jpg');

        $response = $this->getJson("/api/clients/{$this->token()}/artwork")
            ->assertOk()
            ->assertJsonPath('next_cursor', null);

        $this->assertSame([$this->item($url), $this->item('https://x.test/plain.jpg')], $this->albumItems($response));
    }

    public function test_the_first_page_starts_with_every_source_logo(): void
    {
        foreach (SourceLogo::KEYS as $key) {
            ArtworkCache::forget(SourceLogo::url($key));
        }

        $response = $this->getJson("/api/clients/{$this->token()}/artwork")->assertOk();

        $logos = collect($response->json('data'))->where('kind', 'source');
        $this->assertSame(
            array_map(fn (string $key) => md5(SourceLogo::url($key)), SourceLogo::KEYS),
            $logos->pluck('hash')->all()
        );
        foreach ($logos as $logo) {
            Storage::disk('public')->assertExists("artwork/{$logo['hash']}/320.jpg");
            $this->assertStringEndsWith("/artwork/{$logo['hash']}/bg_1024x600.jpg", $logo['proxy_bg']);
            $this->assertStringEndsWith("/artwork/{$logo['hash']}/bg_320x480.jpg", $logo['proxy_bg_320x480']);
        }
    }

    public function test_recently_played_albums_come_first_then_the_rest(): void
    {
        $neverPlayed = $this->album('Never played', [['url' => 'https://x.test/never.jpg']]);
        $older = $this->album('Older', [['url' => 'https://x.test/older.jpg']]);
        $newest = $this->album('Newest', [['url' => 'https://x.test/newest.jpg']]);
        $this->play($older, '2026-09-01 10:00:00');
        $this->play($newest, '2026-09-02 10:00:00');
        foreach (['never', 'older', 'newest'] as $name) {
            $this->cacheProcessed("https://x.test/{$name}.jpg");
        }

        $response = $this->getJson("/api/clients/{$this->token()}/artwork");

        $this->assertSame(
            [md5('https://x.test/newest.jpg'), md5('https://x.test/older.jpg'), md5('https://x.test/never.jpg')],
            array_column($this->albumItems($response), 'hash')
        );
    }

    public function test_covers_without_a_background_are_left_out(): void
    {
        $url = 'https://x.test/old-entry.jpg';
        $this->album('Old entry', [['url' => $url]]);
        ArtworkCache::put($url, ['proxy_512' => 'a', 'proxy_320' => 'b', 'proxy_120' => 'c', 'colors' => [], 'safe_colors' => []]);

        $this->assertSame([], $this->albumItems($this->getJson("/api/clients/{$this->token()}/artwork")));
    }

    public function test_albums_sharing_a_cover_are_listed_once(): void
    {
        $url = 'https://x.test/shared.jpg';
        $this->album('Deluxe', [['url' => $url]]);
        $this->album('Standard', [['url' => $url]]);
        $this->cacheProcessed($url);

        $this->assertCount(1, $this->albumItems($this->getJson("/api/clients/{$this->token()}/artwork")));
    }

    public function test_an_expired_cache_entry_falls_back_to_the_files_on_disk(): void
    {
        $url = 'https://x.test/expired.jpg';
        $hash = md5($url);
        $this->album('Expired', [['url' => $url]]);
        foreach (['320.jpg', '120.jpg', 'bg_1024x600.jpg', 'bg_320x480.jpg'] as $file) {
            Storage::disk('public')->put("artwork/{$hash}/{$file}", 'jpg');
        }

        $items = $this->albumItems($this->getJson("/api/clients/{$this->token()}/artwork"));

        $this->assertSame([[
            'kind' => 'album',
            'hash' => $hash,
            'proxy_320' => Storage::disk('public')->url("artwork/{$hash}/320.jpg"),
            'proxy_120' => Storage::disk('public')->url("artwork/{$hash}/120.jpg"),
            'proxy_bg' => Storage::disk('public')->url("artwork/{$hash}/bg_1024x600.jpg"),
            'proxy_bg_320x480' => Storage::disk('public')->url("artwork/{$hash}/bg_320x480.jpg"),
        ]], $items);
    }

    public function test_it_paginates_with_an_offset_cursor(): void
    {
        $size = ClientController::ARTWORK_PAGE_SIZE;
        foreach (range(1, $size + 5) as $i) {
            $url = "https://x.test/{$i}.jpg";
            $this->cacheProcessed($url);
            $this->album("Album {$i}", [['url' => $url]]);
        }
        $token = $this->token();

        $first = $this->getJson("/api/clients/{$token}/artwork")->assertOk();
        $this->assertCount($size, $this->albumItems($first));
        $this->assertCount($size + count(SourceLogo::KEYS), $first->json('data'));
        $this->assertSame($size, $first->json('next_cursor'));

        $second = $this->getJson("/api/clients/{$token}/artwork?cursor={$first->json('next_cursor')}")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('next_cursor', null);
        $this->assertSame([], collect($second->json('data'))->where('kind', 'source')->all(), 'Logos are only on the first page');
        $this->assertSame(md5('https://x.test/'.($size + 1).'.jpg'), $second->json('data.0.hash'));
    }

    public function test_it_does_not_queue_processing(): void
    {
        Queue::fake();
        $this->album('Not processed', [['url' => 'https://x.test/unprocessed.jpg']]);

        $this->assertSame([], $this->albumItems($this->getJson("/api/clients/{$this->token()}/artwork")->assertOk()));

        Queue::assertNothingPushed();
    }

    public function test_an_invalid_cursor_is_rejected(): void
    {
        $this->getJson("/api/clients/{$this->token()}/artwork?cursor=abc")->assertUnprocessable();
    }

    public function test_an_unknown_token_returns_404(): void
    {
        $this->getJson('/api/clients/nope/artwork')->assertNotFound();
    }
}
