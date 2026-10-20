<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Spotify\LibrespotStream;
use App\Integrations\Spotify\MusicPlayerDriver as SpotifyDriver;
use App\Integrations\Spotify\Services\DeviceListener;
use App\Models\Device;
use App\Services\SpotifyTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakeReportingStreamDriver;
use Tests\Support\FakeSpotifyDriver;
use Tests\Support\FakeStreamDriver;
use Tests\TestCase;

/** While Spotify is paused, the receiver's speaker stays routed as long as it still plays the stream. */
class SpotifyStreamRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Device $spotify;

    private Device $speaker;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        FakeSpotifyDriver::reset();
        FakeReportingStreamDriver::$loaded = null;
        FakeReportingStreamDriver::$throw = false;
        config([
            'librespot.enabled' => true,
            'librespot.name' => 'Test Receiver',
            'librespot.icecast.port' => 8123,
            'librespot.icecast.mount' => 'spotify.mp3',
            'app.stream_url' => 'http://192.168.1.48:8080',
        ]);
        $this->app->bind(SpotifyDriver::class, fn ($app, array $params) => new FakeSpotifyDriver($params['device']));

        $this->spotify = Device::factory()->create(['device_driver' => SpotifyDriver::class, 'device_driver_name' => 'Spotify']);
        DeviceCache::updateState($this->spotify->id, State::Standby);
    }

    private function speaker(string $driver = FakeReportingStreamDriver::class): Device
    {
        $device = Device::factory()->driver($driver)->create();
        LibrespotStream::routeTo($device->id);

        return $device;
    }

    private function paused(string $connectName = 'Test Receiver'): SpotifyWebAPI
    {
        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('getMyCurrentPlaybackInfo')->andReturn([
            'is_playing' => false, 'progress_ms' => 10_000, 'shuffle_state' => false, 'repeat_state' => 'off',
            'device' => ['name' => $connectName],
            'item' => ['id' => 'abc', 'name' => 'Song', 'duration_ms' => 200_000, 'artists' => [['name' => 'Artist']], 'album' => ['name' => 'Album', 'images' => []]],
        ]);
        $api->shouldReceive('myTracksContains')->andReturn([true]);

        return $api;
    }

    private function poll(SpotifyWebAPI $api): void
    {
        (new DeviceListener(Mockery::mock(SpotifyTokenService::class)))->poll($this->spotify->id, $api);
    }

    public function test_a_speaker_still_playing_the_stream_stays_routed_while_spotify_is_paused(): void
    {
        $speaker = $this->speaker();
        DeviceCache::updateState($speaker->id, State::Playing);
        FakeReportingStreamDriver::$loaded = 'http://10.0.0.5:8123/spotify.mp3'; // the speaker knows the server by another address

        $this->poll($this->paused());

        $this->assertSame($speaker->id, DeviceCache::getSpotifyRoutedDeviceId());
    }

    public function test_routing_ends_when_the_speaker_plays_something_else(): void
    {
        $speaker = $this->speaker();
        DeviceCache::updateState($speaker->id, State::Playing);
        FakeReportingStreamDriver::$loaded = 'http://192.168.1.48:8080/stream/12.flac';

        $this->poll($this->paused());

        $this->assertNull(DeviceCache::getSpotifyRoutedDeviceId());
    }

    public function test_routing_ends_when_the_speaker_holds_nothing_and_stays_when_it_cannot_be_asked(): void
    {
        $speaker = $this->speaker();
        DeviceCache::updateState($speaker->id, State::Playing);

        $this->poll($this->paused());
        $this->assertNull(DeviceCache::getSpotifyRoutedDeviceId());

        FakeReportingStreamDriver::$throw = true;
        $this->poll($this->paused());
        $this->assertSame($speaker->id, DeviceCache::getSpotifyRoutedDeviceId());
    }

    public function test_a_speaker_that_cannot_report_its_url_is_assumed_to_play_the_stream(): void
    {
        $speaker = $this->speaker(FakeStreamDriver::class);
        DeviceCache::updateState($speaker->id, State::Playing);

        $this->poll($this->paused());

        $this->assertSame($speaker->id, DeviceCache::getSpotifyRoutedDeviceId());
    }

    public function test_another_connect_speaker_keeps_the_old_rule(): void
    {
        $speaker = Device::factory()->driver(FakeReportingStreamDriver::class)->create();
        \App\Models\DeviceMeta::create(['device_id' => $speaker->id, 'key' => 'spotify_connect_name', 'value' => 'Living Room Speaker']);
        DeviceCache::updateState($speaker->id, State::Playing);
        FakeReportingStreamDriver::$loaded = 'http://192.168.1.48:8123/spotify.mp3';

        $this->poll($this->paused('Living Room Speaker'));

        $this->assertNull(DeviceCache::getSpotifyRoutedDeviceId());
    }

    public function test_an_idle_speaker_stays_routed_as_before(): void
    {
        $speaker = $this->speaker();
        DeviceCache::updateState($speaker->id, State::Standby);

        $this->poll($this->paused());

        $this->assertSame($speaker->id, DeviceCache::getSpotifyRoutedDeviceId());
    }
}
