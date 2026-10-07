<?php

namespace Tests\Feature;

use App\Domain\Device\Cache\Modes;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\PlaybackModes;
use App\Domain\Device\RepeatMode;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Events\Device\PlaybackModesUpdated;
use App\Integrations\Contracts\LikeInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Sonos\MusicPlayerDriver as SonosDriver;
use App\Integrations\Spotify\MusicPlayerDriver as SpotifyDriver;
use App\Integrations\Spotify\Services\DeviceListener;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Services\SpotifyTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakePlayerDriver;
use Tests\Support\FakePowerDriver;
use Tests\Support\FakeSpotifyDriver;
use Tests\TestCase;

/** A mapped speaker playing Spotify Connect and the Spotify device behave as one device. */
class SpotifyRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Device $spotify;

    private Device $speaker;

    protected function setUp(): void
    {
        parent::setUp();

        // Playback history enrichment would otherwise reach out to the network.
        Http::preventStrayRequests();
        Http::fake();
        FakeSpotifyDriver::reset();
        FakePlayerDriver::reset();
        FakePowerDriver::reset();
        $this->app->bind(SpotifyDriver::class, fn ($app, array $params) => new FakeSpotifyDriver($params['device']));

        $this->spotify = $this->makeDevice('Spotify', SpotifyDriver::class, 'Spotify');
        $this->speaker = $this->makeDevice('Living Room', FakePlayerDriver::class);
        DeviceMeta::create(['device_id' => $this->speaker->id, 'key' => 'spotify_connect_name', 'value' => 'Living Room Speaker']);
    }

    private function makeDevice(string $name, string $driver, string $driverName = 'Fake'): Device
    {
        $device = Device::factory()->create([
            'device_name' => $name,
            'device_driver' => $driver,
            'device_driver_name' => $driverName,
        ]);
        DeviceCache::updateState($device->id, State::Standby);

        return $device;
    }

    private function route(?Device $to = null): void
    {
        DeviceCache::setSpotifyRoutedDevice(($to ?? $this->speaker)->id);
    }

    private function playback(string $connectName, bool $playing = true, bool $shuffle = true, string $repeat = 'context'): array
    {
        return [
            'is_playing' => $playing,
            'progress_ms' => 10_000,
            'shuffle_state' => $shuffle,
            'repeat_state' => $repeat,
            'device' => ['name' => $connectName],
            'item' => [
                'id' => 'abc',
                'name' => 'Song',
                'duration_ms' => 200_000,
                'artists' => [['name' => 'Artist']],
                'album' => ['name' => 'Album', 'images' => []],
            ],
        ];
    }

    private function listener(): DeviceListener
    {
        return new DeviceListener(Mockery::mock(SpotifyTokenService::class));
    }

    /** @param array<int, array|null> $responses */
    private function api(array $responses): SpotifyWebAPI
    {
        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('getMyCurrentPlaybackInfo')->andReturnValues($responses);
        $api->shouldReceive('myTracksContains')->andReturn([true]);

        return $api;
    }

    private function lastModes(Device $device): ?array
    {
        $publishes = array_values(array_filter($this->mqtt->published, fn ($p) => $p['topic'] === "remoment/player/{$device->id}/modes"));

        return $publishes ? json_decode(end($publishes)['message'], true) : null;
    }

    // --- Modes follow the routing ---

    public function test_modes_are_reported_for_the_routed_speaker(): void
    {
        $listener = $this->listener();
        $listener->poll($this->spotify->id, $this->api([$this->playback('Living Room Speaker')]));

        $expected = ['shuffle' => true, 'repeat' => 'all', 'liked' => true];
        $this->assertSame($expected, $this->lastModes($this->speaker));
        $this->assertSame($expected, Modes::get($this->speaker->id)->toArray());
        $this->assertNull($this->lastModes($this->spotify));
        $this->assertSame($this->speaker->id, DeviceCache::getSpotifyRoutedDeviceId());
    }

    public function test_the_speaker_gets_its_own_modes_back_when_routing_ends(): void
    {
        // The speaker's own listener reported its modes before Spotify took over.
        event(new PlaybackModesUpdated((string) $this->speaker->id, new PlaybackModes(shuffle: false, repeat: RepeatMode::One)));

        $listener = $this->listener();
        $api = $this->api([
            $this->playback('Living Room Speaker'),
            $this->playback('Phone'),
        ]);

        $listener->poll($this->spotify->id, $api);
        $this->assertSame(['shuffle' => true, 'repeat' => 'all', 'liked' => true], $this->lastModes($this->speaker));

        // Playback moved to an unmapped Connect device (the phone).
        $listener->poll($this->spotify->id, $api);
        $this->assertSame(['shuffle' => false, 'repeat' => 'one', 'liked' => null], $this->lastModes($this->speaker));
        $this->assertSame(['shuffle' => true, 'repeat' => 'all', 'liked' => true], $this->lastModes($this->spotify));
        $this->assertNull(DeviceCache::getSpotifyRoutedDeviceId());
    }

    public function test_a_speaker_without_own_modes_is_cleared_when_routing_ends(): void
    {
        $listener = $this->listener();
        $api = $this->api([$this->playback('Living Room Speaker'), null]);

        $listener->poll($this->spotify->id, $api);
        $listener->poll($this->spotify->id, $api);

        $this->assertSame(['shuffle' => null, 'repeat' => null, 'liked' => null], $this->lastModes($this->speaker));
        $this->assertSame(['shuffle' => null, 'repeat' => null, 'liked' => null], Modes::get($this->speaker->id)->toArray());
    }

    public function test_the_spotify_device_modes_are_cleared_when_playback_moves_to_a_speaker(): void
    {
        $listener = $this->listener();
        $api = $this->api([$this->playback('Phone'), $this->playback('Living Room Speaker')]);

        $listener->poll($this->spotify->id, $api);
        $this->assertSame(['shuffle' => true, 'repeat' => 'all', 'liked' => true], $this->lastModes($this->spotify));

        $listener->poll($this->spotify->id, $api);
        $this->assertSame(['shuffle' => null, 'repeat' => null, 'liked' => null], $this->lastModes($this->spotify));
        $this->assertSame(['shuffle' => true, 'repeat' => 'all', 'liked' => true], $this->lastModes($this->speaker));
    }

    public function test_the_speakers_own_modes_are_held_back_while_routed(): void
    {
        $this->route();
        event(new PlaybackModesUpdated((string) $this->speaker->id, new PlaybackModes(shuffle: true, repeat: RepeatMode::All), routed: true));

        // Its own listener reports something else meanwhile: not shown, but remembered.
        event(new PlaybackModesUpdated((string) $this->speaker->id, new PlaybackModes(shuffle: false, repeat: RepeatMode::Off)));

        $this->assertSame(['shuffle' => true, 'repeat' => 'all', 'liked' => null], $this->lastModes($this->speaker));
        $this->assertSame(['shuffle' => false, 'repeat' => 'off', 'liked' => null], Modes::own($this->speaker->id)?->toArray());
    }

    public function test_routing_is_kept_while_spotify_is_paused_on_the_speaker(): void
    {
        $listener = $this->listener();
        $api = $this->api([$this->playback('Living Room Speaker'), $this->playback('Living Room Speaker', playing: false)]);

        $listener->poll($this->spotify->id, $api);
        $listener->poll($this->spotify->id, $api);

        $this->assertSame($this->speaker->id, DeviceCache::getSpotifyRoutedDeviceId());
        $this->assertSame(State::Standby, DeviceCache::getState($this->speaker->id));
    }

    public function test_routing_ends_when_paused_and_the_speaker_plays_something_else(): void
    {
        $listener = $this->listener();
        $api = $this->api([$this->playback('Living Room Speaker', playing: false)]);
        DeviceCache::updateState($this->speaker->id, State::Playing);

        $listener->poll($this->spotify->id, $api);

        $this->assertNull(DeviceCache::getSpotifyRoutedDeviceId());
    }

    // --- Playback commands follow the routing ---

    public function test_playback_commands_on_the_routed_speaker_go_to_spotify(): void
    {
        $this->route();
        DeviceCache::updateState($this->speaker->id, State::Playing);
        $id = $this->speaker->id;

        $this->postJson("/api/devices/{$id}/play")->assertOk();
        $this->postJson("/api/devices/{$id}/pause")->assertOk();
        $this->postJson("/api/devices/{$id}/next")->assertOk();
        $this->postJson("/api/devices/{$id}/previous")->assertOk();
        $this->putJson("/api/devices/{$id}/seek", ['position' => 90])->assertOk();
        $this->getJson("/api/devices/{$id}/queue")->assertOk()->assertJsonPath('up_next.0.name', 'Spotify Next');
        $this->putJson("/api/devices/{$id}/shuffle", ['shuffle' => true])->assertOk();
        $this->putJson("/api/devices/{$id}/repeat", ['repeat' => 'one'])->assertOk();
        $this->putJson("/api/devices/{$id}/like", ['liked' => true])->assertOk();

        $this->assertSame(
            ['play', 'pause', 'next', 'previous', 'seek', 'getUpNext', 'setShuffle', 'setRepeat', 'setLiked'],
            array_column(FakeSpotifyDriver::$calls, 0),
        );
        $this->assertSame([], FakePlayerDriver::$calls);

        // The change is recorded for the speaker, where Spotify's modes are shown.
        $this->assertSame(['shuffle' => true, 'repeat' => 'one', 'liked' => true], $this->lastModes($this->speaker));
    }

    public function test_volume_mute_and_sources_stay_with_the_speaker(): void
    {
        $this->route();
        DeviceCache::updateState($this->speaker->id, State::Playing);
        $id = $this->speaker->id;

        $this->putJson("/api/devices/{$id}/volume", ['volume' => 40])->assertOk();
        $this->putJson("/api/devices/{$id}/mute", ['muted' => true])->assertOk();
        $this->postJson("/api/devices/{$id}/sources/activate", ['source_id' => 'RADIO'])->assertOk();

        $this->assertSame(['setVolume', 'mute', 'activateSource'], array_column(FakePlayerDriver::$calls, 0));
        $this->assertSame([], FakeSpotifyDriver::$calls);
    }

    public function test_commands_use_the_speakers_own_driver_when_not_routed(): void
    {
        DeviceCache::updateState($this->speaker->id, State::Playing);

        $this->postJson("/api/devices/{$this->speaker->id}/play")->assertOk();

        $this->assertSame(['play'], array_column(FakePlayerDriver::$calls, 0));
        $this->assertSame([], FakeSpotifyDriver::$calls);
    }

    public function test_capabilities_and_the_unsupported_check_are_merged_while_routed(): void
    {
        $speaker = $this->makeDevice('Kitchen', FakePowerDriver::class);
        DeviceCache::updateState($speaker->id, State::Playing);

        $this->getJson("/api/devices/{$speaker->id}")->assertJsonPath('data.capabilities', ['power']);
        $this->postJson("/api/devices/{$speaker->id}/play")->assertStatus(422);

        $this->route($speaker);

        $merged = ['media_controls', 'seek', 'queue', 'queue_jump', 'shuffle', 'repeat', 'like', 'power'];
        $this->getJson("/api/devices/{$speaker->id}")->assertJsonPath('data.capabilities', $merged);
        $this->getJson('/api/devices')->assertJsonFragment(['id' => $speaker->id, 'capabilities' => $merged]);
        $this->postJson("/api/devices/{$speaker->id}/play")->assertOk();
        $this->putJson("/api/devices/{$speaker->id}/power", ['on' => false])->assertOk();

        $this->assertSame(['play'], array_column(FakeSpotifyDriver::$calls, 0));
        $this->assertSame(['standby'], FakePowerDriver::$calls);
    }

    // --- One device in the API ---

    public function test_device_list_omits_the_spotify_device_while_routed(): void
    {
        $this->getJson('/api/devices')->assertJsonCount(2, 'data');

        $this->route();

        $this->getJson('/api/devices')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->speaker->id);
    }

    public function test_client_lists_omit_the_spotify_device_while_routed(): void
    {
        $client = Client::create([
            'status' => 'approved',
            'type' => 'multi',
            'registration_token' => Client::generateToken(),
            'api_token' => Client::generateToken(),
        ]);
        $client->devices()->attach([$this->spotify->id, $this->speaker->id]);
        $this->route();

        $this->getJson("/api/clients/{$client->api_token}/devices")
            ->assertJsonCount(1, 'devices')
            ->assertJsonPath('devices.0.id', $this->speaker->id)
            ->assertJsonPath('devices.0.capabilities', ['media_controls', 'volume_control', 'source_control', 'source_activation', 'multi_room', 'seek', 'queue', 'queue_jump', 'shuffle', 'repeat', 'like']);

        $this->getJson("/api/clients/status/{$client->registration_token}")
            ->assertJsonCount(1, 'devices');
    }

    public function test_a_client_with_only_the_spotify_device_keeps_it(): void
    {
        $client = Client::create([
            'status' => 'approved',
            'type' => 'single',
            'registration_token' => Client::generateToken(),
            'api_token' => Client::generateToken(),
        ]);
        $client->devices()->attach($this->spotify->id);
        $this->route();

        $this->getJson("/api/clients/{$client->api_token}/devices")
            ->assertJsonCount(1, 'devices')
            ->assertJsonPath('devices.0.id', $this->spotify->id);
    }

    public function test_a_routed_sonos_is_controlled_by_its_own_driver_except_like(): void
    {
        // Spotify reports Sonos as a restricted device: Web API commands are refused.
        $sonos = $this->makeDevice('Roam', SonosDriver::class, 'Sonos');
        $this->route($sonos);

        $this->assertSame($sonos->id, SpotifyRouting::deviceFor($sonos, MediaControlsInterface::class)->id);
        $this->assertSame($sonos->id, SpotifyRouting::deviceFor($sonos, SeekInterface::class)->id);
        $this->assertSame($sonos->id, SpotifyRouting::deviceFor($sonos, ShuffleInterface::class)->id);
        $this->assertSame($this->spotify->id, SpotifyRouting::deviceFor($sonos, LikeInterface::class)->id);
    }
}
