<?php

namespace Tests\Feature;

use App\Domain\Developer\CapabilityProfile;
use App\Domain\Developer\StreamDiagnostics;
use App\Domain\Developer\Tones;
use App\Domain\Device\DeviceCache;
use App\Domain\Storage\Proxies;
use App\Domain\Storage\StreamUrl;
use App\Events\Device\NowPlayingUpdated;
use App\Livewire\Developer\CapabilityProbe;
use App\Livewire\Developer\Maintenance;
use App\Livewire\Developer\MqttConsole;
use App\Livewire\Developer\PlaybackTests;
use App\Livewire\Developer\QualityTest;
use App\Livewire\Developer\SimulateNowPlaying;
use App\Livewire\Developer\StreamUrlTester;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\User;
use App\Services\MqttService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** /settings/developer: the developer tools (docs/architecture/developer-tools.md). */
class DeveloperPageTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    public function test_the_page_needs_the_admin_login(): void
    {
        $this->get('/settings/developer')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/settings/developer')->assertOk()->assertSee('Quality test');
    }

    public function test_the_mqtt_tree_gets_its_address_through_data_attributes(): void
    {
        config(['mqtt.ws_url' => 'ws://broker.example:9001', 'mqtt.public_ws_port' => 9001]);

        $html = $this->actingAs(User::factory()->create())->get('/settings/developer')->assertOk()->getContent();

        $this->assertStringContainsString('data-url="ws://broker.example:9001"', $html);
        $this->assertStringContainsString('x-data="mqttTree($el.dataset.url, $el.dataset.port)"', $html);
        $this->assertStringNotContainsString('@js(', $html); // an uncompiled directive in an attribute breaks Alpine
    }

    public function test_dev_profiles_are_never_offered_to_players(): void
    {
        $this->assertArrayHasKey('mp3-128', Proxies::profiles());
        $this->assertArrayNotHasKey('mp3-128', Proxies::playerProfiles());
        $this->assertArrayHasKey('mp3-lo', Proxies::playerProfiles());
        $this->assertArrayNotHasKey('mp3-lo', Proxies::devProfiles());
    }

    public function test_a_stream_url_asks_for_the_profile_only_inside_the_override(): void
    {
        config(['app.stream_url' => 'http://192.168.1.9']);

        $inside = StreamUrl::withProfile('mp3-128', fn () => [StreamUrl::for(7), StreamUrl::withExtension(7, 'flac')]);

        $this->assertSame(['http://192.168.1.9/stream/7?profile=mp3-128', 'http://192.168.1.9/stream/7.mp3?profile=mp3-128'], $inside);
        $this->assertSame('http://192.168.1.9/stream/7', StreamUrl::for(7));
        $this->assertSame(7, StreamUrl::trackId($inside[1]));
    }

    public function test_the_override_is_cleared_when_the_callback_throws(): void
    {
        try {
            StreamUrl::withProfile('mp3-64', fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $this->assertNull(StreamUrl::overrideProfile());
    }

    public function test_quality_test_refuses_an_unknown_profile_and_missing_choices(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(QualityTest::class)->call('play')->assertSet('result.ok', false);
        Livewire::test(QualityTest::class)->call('generate', 'nope')->assertSet('result', null);
    }

    public function test_the_ladder_is_grouped_and_lists_high_resolution_rungs(): void
    {
        $this->actingAs(User::factory()->create());
        $artist = Artist::create(['name' => 'A', 'source' => 'files']);
        $track = $this->makeLibraryTrack(Album::create(['artist_id' => $artist->id, 'name' => 'B', 'source' => 'files']), 'Song');

        Livewire::test(QualityTest::class)->call('choose', $track->id)
            ->assertSee('Lossless')->assertSee('Uncompressed')->assertSee('FLAC 24-bit / 192 kHz')->assertSee('WAV 24-bit / 192 kHz')->assertSee('Player profiles');
    }

    public function test_simulate_now_playing_fills_the_cache_and_fires_the_event(): void
    {
        Event::fake([NowPlayingUpdated::class]);
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();

        Livewire::test(SimulateNowPlaying::class)
            ->set('deviceId', $device->id)->set('track', 'Dev Song')
            ->call('nowPlaying');

        $this->assertSame('Dev Song', DeviceCache::getNowPlaying($device->id)->track->name);
        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->deviceId === (string) $device->id);
    }

    public function test_the_mqtt_console_publishes_and_refuses_wildcards(): void
    {
        $this->actingAs(User::factory()->create());
        $mqtt = $this->mock(MqttService::class);
        $mqtt->shouldReceive('publish')->once()->with('remoment/test', 'hi', 0, true);

        Livewire::test(MqttConsole::class)->set('topic', 'remoment/test')->set('payload', 'hi')->set('retain', true)->call('publish');
        Livewire::test(MqttConsole::class)->set('topic', 'remoment/#')->call('publish')->assertSet('message', 'Give a plain topic (no # or + wildcards).');
    }

    public function test_the_stream_tester_probes_both_urls(): void
    {
        config(['app.stream_url' => 'http://192.168.1.9']);
        Http::fake(['*' => Http::response('', 200, ['Content-Type' => 'audio/flac'])]);
        $this->actingAs(User::factory()->create());
        $artist = Artist::create(['name' => 'A', 'source' => 'files']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'B', 'source' => 'files']);
        $track = $this->makeLibraryTrack($album, 'Song');

        $component = Livewire::test(StreamUrlTester::class)->set('trackId', $track->id)->call('probe');

        $this->assertCount(4, $component->get('probes'));
        $this->assertSame(200, $component->get('probes')[0]['status']);
    }

    public function test_the_capability_probe_keeps_verdicts_per_device(): void
    {
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();

        Livewire::test(CapabilityProbe::class)->set('deviceId', $device->id)
            ->call('mark', 'flac-24-96', 'ok')->call('mark', 'flac-24-192', 'broken')->call('mark', 'nonsense', 'ok')
            ->assertSee('FLAC 24-bit / 96 kHz');

        $this->assertSame(['flac-24-96' => 'ok', 'flac-24-192' => 'broken'], CapabilityProfile::get($device));
        $this->assertSame(96000, CapabilityProfile::summary($device)['Lossless']['max_rate']);

        CapabilityProfile::mark($device, 'flac-24-96', null);
        $this->assertSame(['flac-24-192' => 'broken'], CapabilityProfile::get($device));
    }

    public function test_the_stream_log_records_requests_and_faults_answer_with_their_status(): void
    {
        $artist = Artist::create(['name' => 'A', 'source' => 'files']);
        $track = $this->makeLibraryTrack(Album::create(['artist_id' => $artist->id, 'name' => 'B', 'source' => 'files']), 'Song');

        $this->get("/stream/{$track->id}");
        $this->assertSame([], StreamDiagnostics::entries());

        StreamDiagnostics::setLogging(true);
        StreamDiagnostics::setFault('503', 100);

        $this->get("/stream/{$track->id}", ['Range' => 'bytes=0-9'])->assertStatus(503);

        $entry = StreamDiagnostics::entries()[0];
        $this->assertSame([$track->id, 503, 'bytes=0-9'], [$entry['track'], $entry['status'], $entry['range']]);

        StreamDiagnostics::setFault(null);
        $this->get("/stream/{$track->id}")->assertStatus(404); // no file behind the fixture, and the 404 is logged too
        $this->assertSame(404, StreamDiagnostics::entries()[0]['status']);
    }

    public function test_tones_are_whitelisted_and_cleaned_up_by_the_maintenance_tab(): void
    {
        $this->get('/dev/tone/not-a-tone.wav')->assertNotFound();
        $this->assertTrue(Tones::known('sine-1k'));
        $this->assertStringEndsWith('/dev/tone/sweep.wav', Tones::url('sweep'));

        $this->actingAs(User::factory()->create());
        Livewire::test(Maintenance::class)->call('testAlert')->assertSee('Raised a test alert')->call('resolveTestAlert')->assertSee('Test alert resolved');
    }

    public function test_playback_tests_say_so_when_a_player_cannot(): void
    {
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();

        Livewire::test(PlaybackTests::class)->set('deviceId', $device->id)->call('tone', 'sine-1k')->assertSet('result.ok', false);
        Livewire::test(PlaybackTests::class)->set('deviceId', $device->id)->call('seekPercent', 50)->assertSet('result.ok', false);
    }
}
