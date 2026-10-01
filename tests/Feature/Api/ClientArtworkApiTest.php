<?php

namespace Tests\Feature\Api;

use App\Domain\Artwork\ArtworkCache;
use App\Http\Controllers\Api\ClientController;
use App\Models\Client;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    private function cacheProcessed(string $url): void
    {
        $hash = md5($url);
        ArtworkCache::put($url, [
            'proxy_512' => "/storage/artwork/{$hash}/512.jpg",
            'proxy_320' => "/storage/artwork/{$hash}/320.jpg",
            'proxy_120' => "/storage/artwork/{$hash}/120.jpg",
            'proxy_bg' => "/storage/artwork/{$hash}/bg_1024x600.jpg",
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

        $this->getJson("/api/clients/{$this->token()}/artwork")
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'hash' => md5($url),
                        'proxy_320' => '/storage/artwork/'.md5($url).'/320.jpg',
                        'proxy_120' => '/storage/artwork/'.md5($url).'/120.jpg',
                    ],
                    [
                        'hash' => md5('https://x.test/plain.jpg'),
                        'proxy_320' => '/storage/artwork/'.md5('https://x.test/plain.jpg').'/320.jpg',
                        'proxy_120' => '/storage/artwork/'.md5('https://x.test/plain.jpg').'/120.jpg',
                    ],
                ],
                'next_cursor' => null,
            ]);
    }

    public function test_albums_sharing_a_cover_are_listed_once(): void
    {
        $url = 'https://x.test/shared.jpg';
        $this->album('Deluxe', [['url' => $url]]);
        $this->album('Standard', [['url' => $url]]);
        $this->cacheProcessed($url);

        $this->getJson("/api/clients/{$this->token()}/artwork")->assertJsonCount(1, 'data');
    }

    public function test_an_expired_cache_entry_falls_back_to_the_files_on_disk(): void
    {
        $url = 'https://x.test/expired.jpg';
        $hash = md5($url);
        $this->album('Expired', [['url' => $url]]);
        Storage::disk('public')->put("artwork/{$hash}/320.jpg", 'jpg');
        Storage::disk('public')->put("artwork/{$hash}/120.jpg", 'jpg');

        $this->getJson("/api/clients/{$this->token()}/artwork")
            ->assertJsonPath('data.0.hash', $hash)
            ->assertJsonPath('data.0.proxy_320', Storage::disk('public')->url("artwork/{$hash}/320.jpg"))
            ->assertJsonPath('data.0.proxy_120', Storage::disk('public')->url("artwork/{$hash}/120.jpg"));
    }

    public function test_it_paginates_with_an_album_id_cursor(): void
    {
        $size = ClientController::ARTWORK_PAGE_SIZE;
        $albums = collect(range(1, $size + 5))->map(function (int $i) {
            $url = "https://x.test/{$i}.jpg";
            $this->cacheProcessed($url);

            return $this->album("Album {$i}", [['url' => $url]]);
        });
        $token = $this->token();

        $first = $this->getJson("/api/clients/{$token}/artwork")->assertOk()->assertJsonCount($size, 'data');
        $this->assertSame($albums[$size - 1]->id, $first->json('next_cursor'));

        $second = $this->getJson("/api/clients/{$token}/artwork?cursor={$first->json('next_cursor')}")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('next_cursor', null);
        $this->assertSame(md5("https://x.test/{$size}.jpg"), $first->json('data.'.($size - 1).'.hash'));
        $this->assertSame(md5('https://x.test/'.($size + 1).'.jpg'), $second->json('data.0.hash'));
    }

    public function test_it_does_not_queue_processing(): void
    {
        Queue::fake();
        $this->album('Not processed', [['url' => 'https://x.test/unprocessed.jpg']]);

        $this->getJson("/api/clients/{$this->token()}/artwork")->assertOk()->assertJsonPath('data', []);

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
