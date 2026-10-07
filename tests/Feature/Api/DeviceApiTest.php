<?php

namespace Tests\Feature\Api;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Jobs\ProcessArtwork;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/**
 * Contract tests for the device REST API. Firmware clients depend on these
 * response shapes, so changes here are breaking changes — update
 * CLAUDE.md's REST API Reference alongside them.
 */
class DeviceApiTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_SHAPE = [
        'id', 'device_name', 'device_brand_name', 'device_product_type', 'device_driver_name',
        'ip_address', 'state', 'last_seen', 'capabilities', 'mqtt_topic',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        FakePlayerDriver::reset();
    }

    private function makeDevice(string $driver = FakePlayerDriver::class, State $state = State::Standby, string $name = 'Living Room'): Device
    {
        $device = Device::create([
            'ip_address' => '10.0.0.10',
            'device_name' => $name,
            'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker',
            'device_driver' => $driver,
            'device_driver_name' => 'Fake',
        ]);

        DeviceCache::updateState($device->id, $state);

        return $device;
    }

    // --- List & detail ---

    public function test_index_returns_device_list_shape(): void
    {
        $device = $this->makeDevice();

        $this->getJson('/api/devices')
            ->assertOk()
            ->assertJsonStructure(['data' => ['*' => self::DEVICE_SHAPE]])
            ->assertJsonPath('data.0.id', $device->id)
            ->assertJsonPath('data.0.state', 'standby')
            ->assertJsonPath('data.0.mqtt_topic', "remoment/player/{$device->id}");
    }

    public function test_capabilities_reflect_driver_contracts(): void
    {
        $full = $this->makeDevice();
        $bare = $this->makeDevice(FakeBareDriver::class, name: 'Bare');

        $this->getJson("/api/devices/{$full->id}")
            ->assertJsonPath('data.capabilities', [
                'media_controls', 'volume_control', 'source_control', 'source_activation',
                'multi_room', 'seek', 'queue', 'queue_jump',
            ]);

        $this->getJson("/api/devices/{$bare->id}")->assertJsonPath('data.capabilities', []);
    }

    public function test_library_playback_capability_is_reported(): void
    {
        $device = $this->makeDevice(\Tests\Support\FakeLibraryPlaybackDriver::class);

        $this->getJson("/api/devices/{$device->id}")->assertJsonPath('data.capabilities', ['library_playback']);
    }

    public function test_show_has_null_now_playing_when_nothing_is_cached(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => [...self::DEVICE_SHAPE, 'now_playing']])
            ->assertJsonPath('data.now_playing', null);
    }

    public function test_show_includes_now_playing_when_playing(): void
    {
        $device = $this->makeDevice(state: State::Playing);
        (new DeviceCache)->updateNowPlaying($device->id, new NowPlaying(
            track: new TrackData(id: 'spotify:track:abc', name: 'Song Title', source: 'spotify', artist: new ArtistData(name: 'Artist Name'), duration: 213),
            state: 'playing',
            position: 42,
            type: 'music',
            platform: 'media',
        ));

        $this->getJson("/api/devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('data.state', 'playing')
            ->assertJsonPath('data.now_playing.track.name', 'Song Title')
            ->assertJsonPath('data.now_playing.track.duration', 213)
            ->assertJsonPath('data.now_playing.track.artist.name', 'Artist Name')
            ->assertJsonPath('data.now_playing.position', 42)
            ->assertJsonPath('data.now_playing.state', 'playing');
    }

    public function test_unknown_device_returns_404(): void
    {
        $this->getJson('/api/devices/999')->assertNotFound();
    }

    // --- Media controls ---

    public function test_media_actions_call_the_driver(): void
    {
        $device = $this->makeDevice();

        foreach (['play', 'pause', 'stop', 'next', 'previous'] as $action) {
            $this->postJson("/api/devices/{$device->id}/{$action}")
                ->assertOk()
                ->assertExactJson(['status' => 'ok', 'action' => $action]);
        }

        $this->assertSame(['play', 'pause', 'stop', 'next', 'previous'], array_column(FakePlayerDriver::$calls, 0));
    }

    public function test_unreachable_device_returns_503(): void
    {
        $device = $this->makeDevice(state: State::Unreachable);

        $this->postJson("/api/devices/{$device->id}/play")
            ->assertStatus(503)
            ->assertExactJson(['error' => 'unreachable', 'message' => 'Device is not reachable.']);

        $this->assertEmpty(FakePlayerDriver::$calls);
    }

    public function test_unsupported_capability_returns_422(): void
    {
        $device = $this->makeDevice(FakeBareDriver::class);

        $this->postJson("/api/devices/{$device->id}/play")
            ->assertStatus(422)
            ->assertExactJson(['error' => 'unsupported', 'message' => 'This device does not support media_controls.']);
    }

    public function test_driver_failure_returns_502(): void
    {
        $device = $this->makeDevice();
        FakePlayerDriver::$throw = new \RuntimeException('timeout');

        $this->postJson("/api/devices/{$device->id}/play")
            ->assertStatus(502)
            ->assertExactJson(['error' => 'driver_error', 'message' => 'The device did not respond: timeout']);
    }

    public function test_unknown_action_is_not_routed(): void
    {
        $device = $this->makeDevice();

        $this->postJson("/api/devices/{$device->id}/explode")->assertNotFound();
    }

    // --- Volume & mute ---

    public function test_get_and_set_volume(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/volume")->assertExactJson(['volume' => 30]);
        $this->putJson("/api/devices/{$device->id}/volume", ['volume' => 45])->assertExactJson(['volume' => 45]);
        $this->assertContains(['setVolume', 45], FakePlayerDriver::$calls);
    }

    public function test_volume_is_validated(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/volume", ['volume' => 101])->assertStatus(422)->assertJsonValidationErrors('volume');
        $this->putJson("/api/devices/{$device->id}/volume", [])->assertStatus(422)->assertJsonValidationErrors('volume');
    }

    public function test_get_and_set_mute(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/mute")->assertExactJson(['muted' => false]);
        $this->putJson("/api/devices/{$device->id}/mute", ['muted' => true])->assertExactJson(['muted' => true]);
        $this->getJson("/api/devices/{$device->id}/mute")->assertExactJson(['muted' => true]);
        $this->putJson("/api/devices/{$device->id}/mute", ['muted' => false])->assertExactJson(['muted' => false]);

        $this->assertContains(['mute', null], FakePlayerDriver::$calls);
        $this->assertContains(['unmute', null], FakePlayerDriver::$calls);
    }

    public function test_mute_requires_a_boolean_and_volume_control(): void
    {
        $device = $this->makeDevice();
        $bare = $this->makeDevice(FakeBareDriver::class, name: 'Bare');

        $this->putJson("/api/devices/{$device->id}/mute", ['muted' => 'loud'])->assertStatus(422)->assertJsonValidationErrors('muted');
        $this->getJson("/api/devices/{$bare->id}/mute")->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    // --- Seek ---

    public function test_seek(): void
    {
        $device = $this->makeDevice(state: State::Playing);

        $this->putJson("/api/devices/{$device->id}/seek", ['position' => 90])
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'position' => 90]);

        $this->assertSame([['seek', 90]], FakePlayerDriver::$calls);
    }

    public function test_seek_validation_and_capability(): void
    {
        $device = $this->makeDevice();
        $bare = $this->makeDevice(FakeBareDriver::class, name: 'Bare');

        $this->putJson("/api/devices/{$device->id}/seek", ['position' => -1])->assertStatus(422)->assertJsonValidationErrors('position');
        $this->putJson("/api/devices/{$bare->id}/seek", ['position' => 10])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'unsupported', 'message' => 'This device does not support seek.']);
    }

    // --- Queue ---

    public function test_queue_returns_up_next_items(): void
    {
        Queue::fake();
        $device = $this->makeDevice(state: State::Playing);

        $this->getJson("/api/devices/{$device->id}/queue")
            ->assertOk()
            ->assertJsonStructure(['up_next' => ['*' => ['name', 'artist', 'album', 'image', 'duration', 'uri', 'artwork']]])
            ->assertJsonPath('up_next.0.name', 'Next Song')
            ->assertJsonPath('up_next.0.duration', 200)
            ->assertJsonPath('up_next.0.artwork', null)
            ->assertJsonPath('up_next.1.artist', null)
            ->assertJsonPath('up_next.1.artwork', null);

        Queue::assertPushed(ProcessArtwork::class, fn ($job) => $job->originalUrl === 'https://img.test/1.jpg');
    }

    public function test_queue_respects_limit(): void
    {
        Queue::fake();
        $device = $this->makeDevice(state: State::Playing);

        $this->getJson("/api/devices/{$device->id}/queue?limit=1")->assertJsonCount(1, 'up_next');
        $this->assertContains(['getUpNext', 1], FakePlayerDriver::$calls);
        $this->getJson("/api/devices/{$device->id}/queue?limit=500")->assertStatus(422);
    }

    public function test_queue_unsupported(): void
    {
        $device = $this->makeDevice(FakeBareDriver::class);

        $this->getJson("/api/devices/{$device->id}/queue")->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    public function test_queue_jump(): void
    {
        $device = $this->makeDevice(state: State::Playing);

        $this->postJson("/api/devices/{$device->id}/queue/jump", ['position' => 2])
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'position' => 2]);

        $this->assertSame([['skipToQueuePosition', 2]], FakePlayerDriver::$calls);
    }

    public function test_queue_jump_validation_and_capability(): void
    {
        $device = $this->makeDevice();
        $bare = $this->makeDevice(FakeBareDriver::class, name: 'Bare');

        $this->postJson("/api/devices/{$device->id}/queue/jump", ['position' => 0])->assertStatus(422)->assertJsonValidationErrors('position');
        $this->postJson("/api/devices/{$bare->id}/queue/jump", ['position' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error', 'unsupported');
    }

    // --- Sources ---

    public function test_sources_are_listed_and_synced(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/sources")
            ->assertOk()
            ->assertJsonPath('sources.0.source_id', 'MUSIC')
            ->assertJsonPath('sources.0.in_use', true)
            ->assertJsonPath('sources.0.provider_name', 'Spotify');

        $this->assertDatabaseHas('device_sources', ['device_id' => $device->id, 'source_id' => 'MUSIC']);
    }

    public function test_activate_source(): void
    {
        $device = $this->makeDevice();

        $this->postJson("/api/devices/{$device->id}/sources/activate", ['source_id' => 'MUSIC'])
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'source_id' => 'MUSIC']);

        $this->assertContains(['activateSource', 'MUSIC'], FakePlayerDriver::$calls);
    }

    // --- Multiroom ---

    public function test_multiroom_maps_peer_ids_to_devices(): void
    {
        $device = $this->makeDevice();
        $kitchen = $this->makeDevice(state: State::Playing, name: 'Kitchen');
        $kitchen->meta()->create(['key' => 'fake_id', 'value' => 'peer-kitchen']);
        FakePlayerDriver::$peers = ['peer-kitchen'];

        $this->getJson("/api/devices/{$device->id}/multiroom")
            ->assertOk()
            ->assertExactJson([
                'joinable' => [['id' => $kitchen->id, 'device_name' => 'Kitchen', 'state' => 'playing']],
                'listeners' => [],
            ]);
    }

    public function test_multiroom_maps_peers_of_another_platform(): void
    {
        $device = $this->makeDevice();
        $mozart = $this->makeDevice(state: State::Playing, name: 'Beolab 28');
        $mozart->meta()->create(['key' => 'mozart_jid', 'value' => 'peer-mozart']);
        FakePlayerDriver::$peers = ['peer-mozart'];

        $this->getJson("/api/devices/{$device->id}/multiroom")
            ->assertOk()
            ->assertJsonPath('joinable.0.id', $mozart->id);
    }

    public function test_multiroom_join_and_leave(): void
    {
        $device = $this->makeDevice();
        $host = $this->makeDevice(name: 'Kitchen');

        $this->postJson("/api/devices/{$device->id}/multiroom/join", ['host_device_id' => $host->id])
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'joined' => 'Kitchen']);
        $this->deleteJson("/api/devices/{$device->id}/multiroom/leave")->assertExactJson(['status' => 'ok']);

        $this->assertSame([['joinSession', $host->id], ['leaveSession', null]], FakePlayerDriver::$calls);
    }
}
