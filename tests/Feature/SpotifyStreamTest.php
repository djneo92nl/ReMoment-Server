<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCapabilities;
use App\Domain\Device\State;
use App\Domain\Spotify\LibrespotEvents;
use App\Domain\Spotify\LibrespotStream;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Integrations\Upnp\MusicPlayerDriver as UpnpDriver;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesSpotify;
use Tests\Support\FakeStreamDriver;
use Tests\TestCase;

/** Spotify through the server: the stream URL, playing it on a device, and librespot's --onevent hook. */
class SpotifyStreamTest extends TestCase
{
    use FakesSpotify;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeStreamDriver::$played = [];
        config([
            'librespot.enabled' => true,
            'librespot.name' => 'Test Receiver',
            'librespot.icecast.port' => 8123,
            'librespot.icecast.mount' => 'spotify.mp3',
            'librespot.event_secret' => 's3cret',
            'app.stream_url' => 'http://192.168.1.48:8080',
        ]);
    }

    private function hook(array $fields, string $secret = 's3cret')
    {
        return $this->postJson('/api/librespot/event', $fields, ['X-Librespot-Secret' => $secret]);
    }

    public function test_the_stream_url_is_the_servers_host_on_icecasts_port(): void
    {
        $this->assertSame('http://192.168.1.48:8123/spotify.mp3', LibrespotStream::url());
        $this->getJson('/api/spotify-stream')->assertOk()
            ->assertJson(['enabled' => true, 'name' => 'Test Receiver', 'url' => 'http://192.168.1.48:8123/spotify.mp3']);
    }

    public function test_the_info_is_empty_while_the_stream_is_off(): void
    {
        config(['librespot.enabled' => false]);

        $this->getJson('/api/spotify-stream')->assertOk()->assertJson(['enabled' => false, 'url' => null]);
    }

    public function test_a_device_that_can_play_a_stream_is_started_on_it(): void
    {
        $device = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();

        $this->assertContains('stream_url', DeviceCapabilities::for($device));
        $this->postJson("/api/devices/{$device->id}/spotify-stream")
            ->assertOk()->assertJson(['status' => 'ok', 'url' => 'http://192.168.1.48:8123/spotify.mp3', 'receiver' => 'Test Receiver']);

        $this->assertSame('http://192.168.1.48:8123/spotify.mp3', FakeStreamDriver::$played[0][0]);
        $this->assertSame('Spotify (Test Receiver)', FakeStreamDriver::$played[0][1]);
        $this->assertSame($device->id, LibrespotEvents::target());
    }

    public function test_starting_the_stream_is_refused_when_off_or_unsupported(): void
    {
        $plain = Device::factory()->inState(State::Standby)->create();

        $this->postJson("/api/devices/{$plain->id}/spotify-stream")->assertStatus(422)->assertJson(['error' => 'unsupported']);

        config(['librespot.enabled' => false]);
        $device = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();

        $this->postJson("/api/devices/{$device->id}/spotify-stream")->assertStatus(422);
        $this->assertSame([], FakeStreamDriver::$played);
    }

    public function test_the_hook_needs_the_secret(): void
    {
        $this->hook(['event' => 'playing'], 'wrong')->assertForbidden();
        config(['librespot.enabled' => false]);
        $this->hook(['event' => 'playing'])->assertStatus(422);
    }

    public function test_hook_events_become_the_now_playing_of_the_speaker_playing_the_stream(): void
    {
        Http::fake();
        Event::fake([NowPlayingUpdated::class, ProgressUpdated::class, NowPlayingEnded::class]);
        LibrespotEvents::setTarget(7);

        $this->hook([
            'event' => 'track_changed', 'track_id' => 'abc123', 'name' => 'Song', 'artists' => "Main Artist\nGuest",
            'album' => 'Album', 'covers' => "http://i/small.jpg\nhttp://i/large.jpg", 'duration_ms' => '213000',
        ])->assertOk();
        $this->hook(['event' => 'playing', 'track_id' => 'abc123', 'position_ms' => '5000', 'duration_ms' => '213000'])->assertOk();

        Event::assertDispatched(NowPlayingUpdated::class, function (NowPlayingUpdated $e) {
            $t = $e->nowPlaying->track;

            return $e->deviceId === '7' && $e->sourceType === 'spotify'
                && $t->name === 'Song' && $t->artist->name === 'Main Artist' && $t->duration === 213
                && $t->id === 'spotify:track:abc123' && $t->images === ['http://i/large.jpg']
                && $e->nowPlaying->album->name === 'Album';
        });
        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->nowPlaying->position === 5);

        $this->hook(['event' => 'seeked', 'position_ms' => '90000'])->assertOk();
        Event::assertDispatched(ProgressUpdated::class, fn ($e) => $e->deviceId === '7' && $e->progress === 90);

        $this->hook(['event' => 'session_disconnected'])->assertOk();
        Event::assertDispatched(NowPlayingEnded::class, fn ($e) => $e->deviceId === '7');
    }

    public function test_playing_the_stream_maps_the_receiver_to_that_speaker_and_moves_it(): void
    {
        $first = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();
        $second = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();

        $this->postJson("/api/devices/{$first->id}/spotify-stream")->assertOk();
        $this->assertSame($first->id, DeviceMeta::where('key', 'spotify_connect_name')->where('value', 'Test Receiver')->value('device_id'));

        $this->postJson("/api/devices/{$second->id}/spotify-stream")->assertOk();
        $this->assertSame([$second->id], DeviceMeta::where('key', 'spotify_connect_name')->where('value', 'Test Receiver')->pluck('device_id')->all());
    }

    public function test_spotifys_own_listener_reports_the_playback_so_the_hook_stays_quiet(): void
    {
        Http::fake();
        Event::fake([NowPlayingUpdated::class, ProgressUpdated::class, NowPlayingEnded::class]);
        $this->connectSpotify();
        $device = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();
        $this->postJson("/api/devices/{$device->id}/spotify-stream")->assertOk();

        $this->hook(['event' => 'track_changed', 'track_id' => 'x', 'name' => 'Song', 'artists' => 'Artist'])->assertOk();
        $this->hook(['event' => 'playing', 'track_id' => 'x'])->assertOk();
        $this->hook(['event' => 'session_disconnected'])->assertOk();

        Event::assertNotDispatched(NowPlayingUpdated::class);
        Event::assertNotDispatched(ProgressUpdated::class);
        Event::assertNotDispatched(NowPlayingEnded::class);
        // The title still reaches Icecast.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/admin/metadata'));
    }

    public function test_the_hook_still_reports_when_spotify_is_not_connected(): void
    {
        Http::fake();
        Event::fake([NowPlayingUpdated::class]);
        $this->disconnectSpotify();
        $device = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();
        $this->postJson("/api/devices/{$device->id}/spotify-stream")->assertOk();

        $this->hook(['event' => 'track_changed', 'track_id' => 'x', 'name' => 'Song', 'artists' => 'Artist'])->assertOk();
        $this->hook(['event' => 'playing', 'track_id' => 'x'])->assertOk();

        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->deviceId === (string) $device->id);
    }

    public function test_the_track_title_goes_to_icecast(): void
    {
        Http::fake();
        config(['librespot.icecast.internal_url' => 'http://icecast:8000']);

        $this->hook(['event' => 'track_changed', 'track_id' => 'x', 'name' => 'Song', 'artists' => 'Artist'])->assertOk();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://icecast:8000/admin/metadata')
            && $r['mode'] === 'updinfo' && $r['mount'] === '/spotify.mp3' && $r['song'] === 'Artist - Song');
    }

    public function test_without_a_target_speaker_the_events_are_ignored(): void
    {
        Http::fake();
        Event::fake([NowPlayingUpdated::class]);
        LibrespotEvents::clearTarget();

        $this->hook(['event' => 'track_changed', 'track_id' => 'x', 'name' => 'Song'])->assertOk();
        $this->hook(['event' => 'playing', 'track_id' => 'x'])->assertOk();

        Event::assertNotDispatched(NowPlayingUpdated::class);
    }

    public function test_a_upnp_renderer_gets_the_stream_as_its_uri_and_never_advances_a_queue(): void
    {
        Http::fake(fn (Request $r) => Http::response('<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><u:R xmlns:u="urn:schemas-upnp-org:service:AVTransport:1"/></s:Body></s:Envelope>'));
        $device = Device::factory()->driver(UpnpDriver::class, 'UPnP')->create(['ip_address' => '10.0.0.30']);
        foreach (['upnp_avtransport_url' => 'http://10.0.0.30/av', 'upnp_renderingcontrol_url' => 'http://10.0.0.30/rc', 'upnp_capabilities' => '[]'] as $key => $value) {
            DeviceMeta::create(['device_id' => $device->id, 'key' => $key, 'value' => $value]);
        }

        $this->assertContains('stream_url', DeviceCapabilities::for($device));
        $device->driver->playStreamUrl('http://192.168.1.48:8123/spotify.mp3', 'Spotify', null);

        Http::assertSent(fn (Request $r) => str_contains($r->body(), 'http://192.168.1.48:8123/spotify.mp3</CurrentURI>') && str_contains($r->body(), 'audio/mpeg'));
        $this->assertNull(UpnpDriver::playing($device->id));
    }

    public function test_the_settings_page_lists_the_devices_that_can_play_the_stream(): void
    {
        Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create(['device_name' => 'Kitchen Renderer']);

        $this->actingAs(\App\Models\User::factory()->create())->get('/settings/spotify')
            ->assertOk()->assertSee('Spotify stream')->assertSee('Kitchen Renderer')->assertSee('Test Receiver');

        config(['librespot.enabled' => false]);

        $this->actingAs(\App\Models\User::factory()->create())->get('/settings/spotify')->assertOk()->assertDontSee('Spotify stream');
    }
}
