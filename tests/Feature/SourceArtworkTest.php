<?php

namespace Tests\Feature;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LogoRenderer;
use App\Domain\Artwork\NowPlayingArtwork;
use App\Domain\Artwork\SourceLogo;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingUpdated;
use App\Jobs\ProcessArtwork;
use App\Listeners\Device\DispatchArtworkProcessing;
use App\Listeners\Device\PublishNowPlayingToMqtt;
use App\Models\Device;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/**
 * Artwork for playback without an image: a generated source/radio logo run
 * through the normal ProcessArtwork pipeline, tagged with `kind`, so clients
 * need no special case (see CLAUDE.md "Artwork Caching").
 */
class SourceArtworkTest extends TestCase
{
    use RefreshDatabase;

    private const COVER = 'https://images.example.test/cover.jpg';

    private const STATION_IMAGE = 'https://images.example.test/station.png';

    private function fakeImage(): string
    {
        $gd = imagecreatetruecolor(300, 300);
        imagefilledrectangle($gd, 0, 0, 149, 299, imagecolorallocate($gd, 200, 30, 30));
        imagefilledrectangle($gd, 150, 0, 299, 299, imagecolorallocate($gd, 30, 30, 200));
        ob_start();
        imagejpeg($gd);

        return ob_get_clean();
    }

    private function publishedArtwork(NowPlaying $nowPlaying): ?array
    {
        app(PublishNowPlayingToMqtt::class)->handle(new NowPlayingUpdated('4', $nowPlaying, 'media'));
        $data = collect($this->mqtt->published)->firstWhere('topic', 'remoment/player/4/data');

        return json_decode($data['message'], true)['artwork'] ?? null;
    }

    private function lineIn(): NowPlaying
    {
        return new NowPlaying(
            type: 'video',
            platform: 'media',
            source: new Source(name: 'Line In', category: 'music', sourceType: 'LINEIN'),
        );
    }

    public static function sources(): array
    {
        return [
            'B&O line-in' => [new Source(name: 'Line In', category: 'music', sourceType: 'LINEIN'), 'line_in'],
            'optical' => [new Source(name: 'Optical', sourceType: 'SPDIF'), 'line_in'],
            'HDMI' => [new Source(name: 'Apple TV', category: 'video', sourceType: 'HDMI'), 'tv'],
            'TV' => [new Source(name: 'TV', sourceType: 'TV'), 'tv'],
            'Bluetooth' => [new Source(name: 'Bluetooth', sourceType: 'BLUETOOTH'), 'bluetooth'],
            'Spotify' => [new Source(name: 'Spotify', category: 'music', sourceType: 'SPOTIFY'), 'spotify'],
            'AirPlay' => [new Source(name: 'AirPlay', sourceType: 'AIRPLAY'), 'cast'],
            'TuneIn' => [new Source(name: 'TuneIn', sourceType: 'TUNEIN'), 'radio'],
            'CD' => [new Source(name: 'CD', sourceType: 'CD'), 'cd'],
            'only a video category' => [new Source(name: 'Input 3', category: 'video', sourceType: 'UNKNOWN'), 'tv'],
            'unknown' => [new Source(name: 'Deezer', category: 'music', sourceType: 'DEEZER'), 'music'],
        ];
    }

    #[DataProvider('sources')]
    public function test_sources_map_to_a_logo(Source $source, string $expected): void
    {
        $this->assertSame($expected, SourceLogo::keyFor(new NowPlaying(source: $source)));
    }

    public function test_short_words_only_match_as_whole_words(): void
    {
        $this->assertSame('music', SourceLogo::match(['Podcast player', 'Abcde']));
        $this->assertSame('cast', SourceLogo::match(['Google Cast']));
    }

    public function test_a_track_without_source_falls_back_to_its_track_source(): void
    {
        $nowPlaying = new NowPlaying(track: new TrackData(name: 'Song', source: 'spotify'));

        $this->assertSame('spotify', SourceLogo::keyFor($nowPlaying));
        $this->assertSame('music', SourceLogo::keyFor(new NowPlaying(track: new TrackData(name: 'Song'))));
    }

    public function test_logo_urls_are_stable_and_round_trip(): void
    {
        $this->assertSame('remoment:logo/v1/line_in', SourceLogo::url('line_in'));
        $this->assertSame('line_in', SourceLogo::keyFromUrl(SourceLogo::url('line_in')));
        $this->assertNull(SourceLogo::keyFromUrl(self::COVER));
        $this->assertNull(SourceLogo::keyFromUrl('remoment:logo/v1/nope'));
    }

    public function test_every_logo_renders_deterministically_as_a_light_glyph_on_dark(): void
    {
        foreach (SourceLogo::KEYS as $key) {
            $png = LogoRenderer::render($key);
            $this->assertSame($png, LogoRenderer::render($key), "{$key} is not deterministic");

            [$w, $h] = getimagesizefromstring($png);
            $this->assertSame([512, 512], [$w, $h]);

            $gd = imagecreatefromstring($png);
            $this->assertLessThan(40, imagecolorat($gd, 5, 5) & 0xFF, "{$key} corner is not dark");

            $brightest = 0;
            for ($y = 128; $y < 384; $y += 4) {
                for ($x = 128; $x < 384; $x += 4) {
                    $brightest = max($brightest, imagecolorat($gd, $x, $y) & 0xFF);
                }
            }
            $this->assertGreaterThan(200, $brightest, "{$key} has no visible glyph");
        }
    }

    public function test_line_in_publishes_a_source_logo_through_the_normal_pipeline(): void
    {
        Http::fake();

        $artwork = $this->publishedArtwork($this->lineIn());

        Http::assertNothingSent();
        $this->assertSame('source', $artwork['kind']);
        foreach (ArtworkCache::REQUIRED_KEYS as $key) {
            $this->assertArrayHasKey($key, $artwork);
        }

        $dir = 'artwork/'.md5(SourceLogo::url('line_in'));
        $this->assertSame(Storage::disk('public')->url("{$dir}/320.jpg"), $artwork['proxy_320']);
        $this->assertSame(Storage::disk('public')->url("{$dir}/bg_1024x600.jpg"), $artwork['proxy_bg']);

        foreach (['320.jpg', '120.jpg', 'bg_1024x600.jpg'] as $file) {
            $bytes = Storage::disk('public')->get("{$dir}/{$file}");
            $this->assertStringContainsString("\xFF\xC0", $bytes, "{$file} is not baseline");
            $this->assertStringNotContainsString("\xFF\xC2", $bytes, "{$file} is progressive");
        }
        $this->assertNotEmpty($artwork['colors']);
        $this->assertNotEmpty($artwork['safe_colors']);
    }

    public function test_a_cached_logo_is_not_rendered_again(): void
    {
        NowPlayingArtwork::resolve($this->lineIn());
        $dir = 'artwork/'.md5(SourceLogo::url('line_in'));
        Storage::disk('public')->delete("{$dir}/320.jpg");

        NowPlayingArtwork::resolve($this->lineIn());

        Storage::disk('public')->assertMissing("{$dir}/320.jpg");
    }

    public function test_a_radio_station_without_any_image_gets_the_radio_logo(): void
    {
        $artwork = $this->publishedArtwork(new NowPlaying(
            track: new TrackData(name: 'Live'),
            platform: 'radio',
            radio: new Radio(name: 'Unknown FM'),
        ));

        $this->assertSame('radio', $artwork['kind']);
        $this->assertStringContainsString('/artwork/'.md5(SourceLogo::url('radio')).'/', $artwork['proxy_320']);
    }

    public function test_a_radio_station_without_a_stream_image_uses_its_image_url_from_the_library(): void
    {
        Queue::fake();
        RadioStation::create(['name' => 'Radio 1', 'image_url' => self::STATION_IMAGE]);
        $nowPlaying = new NowPlaying(track: new TrackData(name: 'Live'), platform: 'radio', radio: new Radio(name: 'Radio 1'));

        (new DispatchArtworkProcessing)->handle(new NowPlayingUpdated('4', $nowPlaying, 'radio'));
        Queue::assertPushed(ProcessArtwork::class, fn (ProcessArtwork $job) => $job->originalUrl === self::STATION_IMAGE);

        Http::fake([self::STATION_IMAGE => Http::response($this->fakeImage())]);
        (new ProcessArtwork(self::STATION_IMAGE))->handle();

        $artwork = NowPlayingArtwork::resolve($nowPlaying);
        $this->assertSame('radio', $artwork['kind']);
        $this->assertStringContainsString('/artwork/'.md5(self::STATION_IMAGE).'/', $artwork['proxy_320']);
    }

    public function test_a_radio_stream_image_is_kind_radio_and_a_cover_is_kind_album(): void
    {
        Http::fake([self::COVER => Http::response($this->fakeImage())]);
        (new ProcessArtwork(self::COVER))->handle();

        $radio = new NowPlaying(track: new TrackData(name: 'Live', images: [self::COVER]), platform: 'radio', radio: new Radio(name: 'R', images: [self::COVER]));
        $album = new NowPlaying(track: new TrackData(name: 'Song'), album: new AlbumData(name: 'Album', images: [self::COVER]));

        $this->assertSame('radio', NowPlayingArtwork::resolve($radio)['kind']);
        $this->assertSame('album', NowPlayingArtwork::resolve($album)['kind']);
    }

    public function test_an_unprocessed_cover_is_still_absent_rather_than_replaced_by_a_logo(): void
    {
        $nowPlaying = new NowPlaying(
            track: new TrackData(name: 'Song', artist: new ArtistData(name: 'Artist')),
            album: new AlbumData(name: 'Album', images: [self::COVER]),
        );

        $this->assertNull(NowPlayingArtwork::resolve($nowPlaying));
        $this->assertNull($this->publishedArtwork($nowPlaying));
    }

    public function test_api_now_playing_carries_the_logo_artwork_with_its_kind(): void
    {
        FakePlayerDriver::reset();
        $device = Device::create([
            'ip_address' => '10.0.0.10', 'device_name' => 'Living Room', 'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker', 'device_driver' => FakePlayerDriver::class, 'device_driver_name' => 'Fake',
        ]);
        DeviceCache::updateState($device->id, State::Playing);
        (new DeviceCache)->updateNowPlaying($device->id, $this->lineIn());

        $this->getJson("/api/devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('data.now_playing.artwork.kind', 'source')
            ->assertJsonStructure(['data' => ['now_playing' => ['artwork' => ['kind', ...ArtworkCache::REQUIRED_KEYS]]]]);
    }
}
