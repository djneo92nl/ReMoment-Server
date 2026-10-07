<?php

namespace Tests\Feature;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LibraryItemArtwork;
use App\Jobs\ProcessArtwork;
use App\Models\Play;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The web UI shows our processed proxies, never the original image URL of DLNA, Spotify or a radio station. */
class ProxyOnlyArtworkTest extends TestCase
{
    use RefreshDatabase;

    private const RAW = 'http://192.168.1.142:8096/Items/abc/Images/Primary/0/def/jpg/480/480/0/0';

    public function test_an_unprocessed_original_is_queued_and_never_returned(): void
    {
        Queue::fake();

        $this->assertNull(LibraryItemArtwork::proxy(self::RAW, 320));
        Queue::assertPushed(ProcessArtwork::class);
    }

    public function test_a_processed_original_gives_its_root_relative_proxy(): void
    {
        Queue::fake();
        ArtworkCache::put(self::RAW, [
            'proxy_512' => 'http://x.test/storage/artwork/h/512.jpg',
            'proxy_320' => 'http://x.test/storage/artwork/h/320.jpg',
            'proxy_120' => 'http://x.test/storage/artwork/h/120.jpg',
            'proxy_bg' => 'http://x.test/storage/artwork/h/bg_1024x600.jpg',
            'proxy_bg_320x480' => 'http://x.test/storage/artwork/h/bg_320x480.jpg',
            'proxy_bg_480x480' => 'http://x.test/storage/artwork/h/bg_480x480.jpg',
            'colors' => ['#111111'], 'safe_colors' => ['#999999'],
        ]);

        $this->assertSame('/storage/artwork/h/120.jpg', LibraryItemArtwork::proxy(self::RAW, 120));
    }

    public function test_the_artwork_thumb_component_does_not_render_an_original_url(): void
    {
        Queue::fake();

        $html = Blade::render('<x-artwork-thumb :src="$src" seed="x" />', ['src' => self::RAW]);

        $this->assertStringNotContainsString('192.168.1.142', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_a_play_without_a_processed_cover_has_no_art_url(): void
    {
        Queue::fake();
        $play = new Play(['image_url' => self::RAW]);

        $this->assertNull($play->artUrl(120));
    }
}
