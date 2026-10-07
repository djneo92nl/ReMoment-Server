<?php

namespace Tests\Feature;

use App\Domain\Artwork\ArtworkBackgrounds;
use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Device\DeviceCache;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingUpdated;
use App\Jobs\ProcessArtwork;
use App\Listeners\Device\DispatchArtworkProcessing;
use App\Listeners\Device\PublishNowPlayingToMqtt;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CachesArtwork;
use Tests\TestCase;

/**
 * Artwork proxies served to clients (see CLAUDE.md "Artwork Caching").
 * The ESP32 client decodes these with TJpgDec, which only reads baseline JPEGs.
 */
class ArtworkProcessingTest extends TestCase
{
    use CachesArtwork;
    use RefreshDatabase;

    private const URL = 'https://images.example.test/cover.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function fakeCover(bool $progressive = false): string
    {
        $gd = imagecreatetruecolor(640, 640);
        imagefilledrectangle($gd, 0, 0, 319, 639, imagecolorallocate($gd, 220, 40, 60));
        imagefilledrectangle($gd, 320, 0, 639, 639, imagecolorallocate($gd, 30, 90, 200));
        imageinterlace($gd, $progressive);

        ob_start();
        imagejpeg($gd, null, 90);

        return ob_get_clean();
    }

    private function fakeHttp(bool $progressive = false): void
    {
        Http::fake([self::URL => Http::response($this->fakeCover($progressive), 200, ['Content-Type' => 'image/jpeg'])]);
    }

    private function dirFor(string $url): string
    {
        return 'artwork/'.md5($url);
    }

    /** SOF0 (FFC0) = baseline, SOF2 (FFC2) = progressive. */
    private function assertBaselineJpeg(string $bytes, string $label): void
    {
        $this->assertStringStartsWith("\xFF\xD8", $bytes, "{$label} is not a JPEG");
        $this->assertStringContainsString("\xFF\xC0", $bytes, "{$label} has no baseline SOF0 marker");
        $this->assertStringNotContainsString("\xFF\xC2", $bytes, "{$label} is progressive");
    }

    public function test_it_generates_all_proxies_and_caches_their_urls(): void
    {
        $this->fakeHttp();

        (new ProcessArtwork(self::URL))->handle();

        $dir = $this->dirFor(self::URL);
        $expected = [
            'proxy_512' => ["{$dir}/512.jpg", 512, 512],
            'proxy_320' => ["{$dir}/320.jpg", 320, 320],
            'proxy_120' => ["{$dir}/120.jpg", 120, 120],
            'proxy_bg' => ["{$dir}/bg_1024x600.jpg", 1024, 600],
            'proxy_bg_320x480' => ["{$dir}/bg_320x480.jpg", 320, 480],
            'proxy_bg_480x480' => ["{$dir}/bg_480x480.jpg", 480, 480],
        ];

        $cached = ArtworkCache::get(self::URL);
        $this->assertTrue(ArtworkCache::has(self::URL));

        foreach ($expected as $key => [$path, $width, $height]) {
            Storage::disk('public')->assertExists($path);
            $bytes = Storage::disk('public')->get($path);

            [$w, $h] = getimagesizefromstring($bytes);
            $this->assertSame([$width, $height], [$w, $h], "{$key} has the wrong size");
            $this->assertBaselineJpeg($bytes, $key);
            $this->assertSame(Storage::disk('public')->url($path), $cached[$key]);
        }

        $this->assertCount(5, $cached['colors']);
        $this->assertCount(5, $cached['safe_colors']);
    }

    public function test_progressive_source_images_still_produce_baseline_proxies(): void
    {
        $this->fakeHttp(progressive: true);

        (new ProcessArtwork(self::URL))->handle();

        foreach (Storage::disk('public')->files($this->dirFor(self::URL)) as $path) {
            $this->assertBaselineJpeg(Storage::disk('public')->get($path), $path);
        }
    }

    public function test_background_is_darkened_for_white_text(): void
    {
        $this->fakeHttp();

        (new ProcessArtwork(self::URL))->handle();

        $gd = imagecreatefromstring(Storage::disk('public')->get($this->dirFor(self::URL).'/bg_1024x600.jpg'));
        $max = 0;
        foreach ([[100, 300], [512, 300], [900, 300], [10, 10], [1014, 590]] as [$x, $y]) {
            $rgb = imagecolorat($gd, $x, $y);
            $max = max($max, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
        }

        $this->assertLessThan(130, $max, 'Background is too bright to sit behind white text');

        $gd = imagecreatefromstring(Storage::disk('public')->get($this->dirFor(self::URL).'/bg_320x480.jpg'));
        $max = 0;
        foreach ([[160, 240], [10, 10], [310, 470], [160, 20]] as [$x, $y]) {
            $rgb = imagecolorat($gd, $x, $y);
            $max = max($max, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
        }
        $this->assertLessThan(130, $max, 'Portrait background is too bright to sit behind white text');
    }

    public function test_a_complete_cache_entry_is_not_reprocessed(): void
    {
        Http::fake();
        ArtworkCache::put(self::URL, $this->completeArtworkEntry());

        (new ProcessArtwork(self::URL))->handle();

        Http::assertNothingSent();
    }

    public function test_an_entry_cached_before_the_new_sizes_is_regenerated(): void
    {
        $this->fakeHttp();
        ArtworkCache::put(self::URL, [
            'proxy_512' => '/storage/old/512.jpg',
            'proxy_320' => '/storage/old/320.jpg',
            'colors' => ['#111111'],
            'safe_colors' => ['#999999'],
        ]);

        $this->assertFalse(ArtworkCache::has(self::URL));

        (new ProcessArtwork(self::URL))->handle();

        $this->assertTrue(ArtworkCache::has(self::URL));
        $this->assertArrayHasKey('proxy_bg', ArtworkCache::get(self::URL));
    }

    private function nowPlaying(): NowPlaying
    {
        return new NowPlaying(
            track: new TrackData(name: 'Song', artist: new ArtistData(name: 'Artist')),
            album: new AlbumData(name: 'Album', images: [self::URL]),
        );
    }

    public function test_playing_a_track_with_an_outdated_entry_queues_regeneration(): void
    {
        Queue::fake();
        ArtworkCache::put(self::URL, ['proxy_512' => 'a', 'proxy_320' => 'b', 'colors' => [], 'safe_colors' => []]);

        (new DispatchArtworkProcessing)->handle(new NowPlayingUpdated('1', $this->nowPlaying(), 'media'));

        Queue::assertPushed(ProcessArtwork::class, fn (ProcessArtwork $job) => $job->originalUrl === self::URL);
    }

    public function test_finishing_a_cover_republishes_data_for_devices_still_playing_it(): void
    {
        $this->fakeHttp();
        $playing = Device::create(['device_name' => 'Kitchen', 'device_brand_name' => 'Test', 'device_product_type' => 'Test', 'device_driver_name' => 'Test', 'ip_address' => '10.0.0.1']);
        $other = Device::create(['device_name' => 'Bedroom', 'device_brand_name' => 'Test', 'device_product_type' => 'Test', 'device_driver_name' => 'Test', 'ip_address' => '10.0.0.2']);
        DeviceCache::updateNowPlaying($playing->id, $this->nowPlaying());

        (new ProcessArtwork(self::URL))->handle();

        $data = collect($this->mqtt->published)->firstWhere('topic', "remoment/player/{$playing->id}/data");
        $this->assertNotNull($data, 'the playing device gets /data again once its cover is ready');
        $this->assertArrayHasKey('proxy_320', json_decode($data['message'], true)['artwork']);
        $this->assertNull(collect($this->mqtt->published)->firstWhere('topic', "remoment/player/{$other->id}/data"));
    }

    public function test_mqtt_data_payload_carries_the_new_artwork_keys(): void
    {
        $this->fakeHttp();
        (new ProcessArtwork(self::URL))->handle();

        app(PublishNowPlayingToMqtt::class)->handle(new NowPlayingUpdated('4', $this->nowPlaying(), 'media'));

        $data = collect($this->mqtt->published)->firstWhere('topic', 'remoment/player/4/data');
        $artwork = json_decode($data['message'], true)['artwork'];

        foreach (['proxy_512', 'proxy_320', 'proxy_120', 'proxy_bg', 'proxy_bg_320x480', 'proxy_bg_480x480', 'colors'] as $key) {
            $this->assertArrayHasKey($key, $artwork);
        }
        $this->assertStringEndsWith('/bg_1024x600.jpg', $artwork['proxy_bg']);
        $this->assertStringEndsWith('/bg_320x480.jpg', $artwork['proxy_bg_320x480']);
    }

    public function test_background_keys_are_proxy_bg_plus_proxy_bg_size_for_extra_sizes(): void
    {
        $this->assertSame('proxy_bg', ArtworkBackgrounds::key('1024x600'));
        $this->assertSame('proxy_bg_320x480', ArtworkBackgrounds::key('320x480'));
        $this->assertSame('proxy_bg_480x480', ArtworkBackgrounds::key('480x480'));

        foreach (array_keys(ArtworkBackgrounds::SIZES) as $size) {
            $key = ArtworkBackgrounds::key($size);
            $this->assertContains($key, ArtworkCache::REQUIRED_KEYS, "{$key} must be required, so old entries regenerate");
            $this->assertSame(ArtworkBackgrounds::file($size), LibraryArtwork::CLIENT_FILES[$key] ?? null, "{$key} must be a client file");
        }
    }

    public function test_entries_from_before_the_portrait_background_are_regenerated(): void
    {
        Queue::fake();
        $old = Arr::except($this->completeArtworkEntry(), 'proxy_bg_320x480');
        ArtworkCache::put(self::URL, $old);
        $this->assertFalse(ArtworkCache::has(self::URL));

        (new DispatchArtworkProcessing)->handle(new NowPlayingUpdated('1', $this->nowPlaying(), 'media'));
        Queue::assertPushed(ProcessArtwork::class, fn (ProcessArtwork $job) => $job->originalUrl === self::URL);

        Album::create(['artist_id' => Artist::create(['name' => 'A', 'source' => 'dlna'])->id, 'name' => 'Old', 'source' => 'dlna', 'images' => [['url' => self::URL]], 'colors' => ['#111111']]);
        $this->artisan('artwork:backfill')->assertSuccessful();
        Queue::assertPushed(ProcessArtwork::class, 2);

        $this->fakeHttp();
        (new ProcessArtwork(self::URL))->handle();

        $this->assertTrue(ArtworkCache::has(self::URL));
        $this->assertStringEndsWith('/bg_320x480.jpg', ArtworkCache::get(self::URL)['proxy_bg_320x480']);
    }

    public function test_backfill_queues_colorless_and_outdated_covers_only(): void
    {
        Queue::fake();
        $artist = Artist::create(['name' => 'A', 'source' => 'dlna']);
        $album = fn (string $url, ?array $colors) => Album::create([
            'artist_id' => $artist->id, 'name' => $url, 'source' => 'dlna',
            'images' => [['url' => $url]], 'colors' => $colors,
        ]);

        $album('https://x.test/no-colors.jpg', null);
        $album('https://x.test/outdated.jpg', ['#111111']);
        $album('https://x.test/complete.jpg', ['#111111']);
        $album('https://x.test/expired.jpg', ['#111111']);
        ArtworkCache::put('https://x.test/outdated.jpg', ['proxy_512' => 'a', 'colors' => ['#111111']]);
        ArtworkCache::put('https://x.test/complete.jpg', $this->completeArtworkEntry());

        $this->artisan('artwork:backfill')->assertSuccessful();

        $queued = Queue::pushed(ProcessArtwork::class)->map(fn (ProcessArtwork $job) => $job->originalUrl)->sort()->values()->all();
        $this->assertSame(['https://x.test/no-colors.jpg', 'https://x.test/outdated.jpg'], $queued);
    }
}
