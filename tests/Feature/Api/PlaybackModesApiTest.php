<?php

namespace Tests\Feature\Api;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\PlaybackModes;
use App\Domain\Device\RepeatMode;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\PlaybackModesUpdated;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeControlsDriver;
use Tests\TestCase;

/** Shuffle / repeat / like: endpoints, now_playing.modes and the MQTT `/modes` topic. */
class PlaybackModesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeControlsDriver::reset();
    }

    private function makeDevice(string $driver = FakeControlsDriver::class, State $state = State::Playing): Device
    {
        $device = Device::create([
            'ip_address' => '10.0.0.10',
            'device_name' => 'Living Room',
            'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker',
            'device_driver' => $driver,
            'device_driver_name' => 'Fake',
        ]);

        DeviceCache::updateState($device->id, $state);

        return $device;
    }

    private function playSomething(Device $device): void
    {
        DeviceCache::updateNowPlaying($device->id, new NowPlaying(
            track: new TrackData(name: 'Song', duration: 200),
            type: 'music',
            platform: 'media',
        ));
    }

    private function modesPublishes(Device $device): array
    {
        return array_values(array_filter($this->mqtt->published, fn ($p) => $p['topic'] === "remoment/player/{$device->id}/modes"));
    }

    public function test_capabilities_are_reported(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}")->assertJsonPath('data.capabilities', ['shuffle', 'repeat', 'like']);
    }

    public function test_set_shuffle(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/shuffle", ['shuffle' => true])
            ->assertOk()
            ->assertExactJson(['shuffle' => true]);

        $this->assertSame([['setShuffle', true]], FakeControlsDriver::$calls);
    }

    public function test_set_repeat(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/repeat", ['repeat' => 'one'])
            ->assertOk()
            ->assertExactJson(['repeat' => 'one']);

        $this->assertSame([['setRepeat', RepeatMode::One]], FakeControlsDriver::$calls);
    }

    public function test_set_like(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/like", ['liked' => true])
            ->assertOk()
            ->assertExactJson(['liked' => true]);

        $this->assertSame([['setLiked', true]], FakeControlsDriver::$calls);
    }

    public function test_invalid_bodies_are_rejected(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/repeat", ['repeat' => 'sometimes'])->assertStatus(422)->assertJsonValidationErrors('repeat');
        $this->putJson("/api/devices/{$device->id}/shuffle", [])->assertStatus(422)->assertJsonValidationErrors('shuffle');
        $this->putJson("/api/devices/{$device->id}/like", ['liked' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('liked');

        $this->assertSame([], FakeControlsDriver::$calls);
    }

    public function test_unsupported_devices_get_422(): void
    {
        $device = $this->makeDevice(FakeBareDriver::class);

        $this->putJson("/api/devices/{$device->id}/shuffle", ['shuffle' => true])->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->putJson("/api/devices/{$device->id}/repeat", ['repeat' => 'all'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->putJson("/api/devices/{$device->id}/like", ['liked' => true])->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    public function test_unreachable_devices_get_503(): void
    {
        $device = $this->makeDevice(state: State::Unreachable);

        $this->putJson("/api/devices/{$device->id}/shuffle", ['shuffle' => true])->assertStatus(503)->assertJsonPath('error', 'unreachable');
        $this->assertSame([], FakeControlsDriver::$calls);
    }

    public function test_driver_errors_get_502_and_change_nothing(): void
    {
        $device = $this->makeDevice();
        FakeControlsDriver::$throw = new \RuntimeException('timeout');

        $this->putJson("/api/devices/{$device->id}/repeat", ['repeat' => 'all'])->assertStatus(502)->assertJsonPath('error', 'driver_error');
        $this->assertSame([], $this->modesPublishes($device));
    }

    public function test_now_playing_modes_are_null_until_known(): void
    {
        $device = $this->makeDevice();
        $this->playSomething($device);

        $this->getJson("/api/devices/{$device->id}")
            ->assertJsonPath('data.now_playing.modes', ['shuffle' => null, 'repeat' => null, 'liked' => null]);
    }

    public function test_listener_reported_modes_appear_in_now_playing_and_on_mqtt(): void
    {
        $device = $this->makeDevice();
        $this->playSomething($device);

        event(new PlaybackModesUpdated((string) $device->id, new PlaybackModes(shuffle: true, repeat: RepeatMode::All)));

        $this->getJson("/api/devices/{$device->id}")
            ->assertJsonPath('data.now_playing.modes', ['shuffle' => true, 'repeat' => 'all', 'liked' => null]);

        $this->assertSame([[
            'topic' => "remoment/player/{$device->id}/modes",
            'message' => '{"shuffle":true,"repeat":"all","liked":null}',
            'retain' => true,
        ]], $this->modesPublishes($device));
    }

    public function test_a_change_through_the_api_updates_the_known_modes_right_away(): void
    {
        $device = $this->makeDevice();
        $this->playSomething($device);
        event(new PlaybackModesUpdated((string) $device->id, new PlaybackModes(shuffle: false, repeat: RepeatMode::Off, liked: false)));

        $this->putJson("/api/devices/{$device->id}/like", ['liked' => true])->assertOk();

        $this->getJson("/api/devices/{$device->id}")
            ->assertJsonPath('data.now_playing.modes', ['shuffle' => false, 'repeat' => 'off', 'liked' => true]);
        $this->assertSame(
            ['{"shuffle":false,"repeat":"off","liked":false}', '{"shuffle":false,"repeat":"off","liked":true}'],
            array_column($this->modesPublishes($device), 'message')
        );
    }

    public function test_unchanged_modes_are_not_republished(): void
    {
        $device = $this->makeDevice();
        $modes = new PlaybackModes(shuffle: true, repeat: RepeatMode::One, liked: true);

        event(new PlaybackModesUpdated((string) $device->id, $modes));
        event(new PlaybackModesUpdated((string) $device->id, $modes));
        event(new PlaybackModesUpdated((string) $device->id, $modes->withShuffle(false)));

        $this->assertCount(2, $this->modesPublishes($device));
    }
}
