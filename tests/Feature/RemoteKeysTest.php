<?php

namespace Tests\Feature;

use App\Domain\Device\RemoteKey;
use App\Domain\Device\State;
use App\Integrations\Philips\VideoPlayerDriver;
use App\Livewire\RemoteCard;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakePlayerDriver;
use Tests\Support\FakeRemoteDriver;
use Tests\TestCase;

/** Remote keys: the Philips key mapping, GET/POST /api/devices/{id}/keys, the MCP tool and the remote card. */
class RemoteKeysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeRemoteDriver::reset();
    }

    private function remote(State $state = State::Playing): Device
    {
        return Device::factory()->driver(FakeRemoteDriver::class)->inState($state)->create();
    }

    private function philips(): Device
    {
        $device = Device::factory()->driver(VideoPlayerDriver::class, 'Philips')->inState(State::Playing)->create(['ip_address' => '10.0.0.70']);
        foreach (['philips_user' => 'dev1234567890abc', 'philips_password' => 'secretkey', 'upnp_avtransport_url' => 'http://10.0.0.70/av'] as $key => $value) {
            DeviceMeta::create(['device_id' => $device->id, 'key' => $key, 'value' => $value]);
        }

        return $device;
    }

    public function test_philips_maps_each_key_to_its_jointspace_name(): void
    {
        Http::fake(['*' => Http::response([])]);
        $driver = $this->philips()->driver;

        foreach ([[RemoteKey::Up, 'CursorUp'], [RemoteKey::Ok, 'Confirm'], [RemoteKey::Menu, 'Options'], [RemoteKey::Red, 'RedColour'], [RemoteKey::ChannelUp, 'ChannelStepUp'], [RemoteKey::Source, 'Source']] as [$key, $name]) {
            $driver->sendKey($key);
            Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/6/input/key') && $r['key'] === $name);
        }

        $driver->sendDigit(7);
        Http::assertSent(fn (Request $r) => $r['key'] === 'Digit7');
        $this->assertContains(RemoteKey::Home, $driver->supportedKeys());
        $this->assertNotContains(RemoteKey::Guide, $driver->supportedKeys());
    }

    public function test_a_philips_key_the_tv_does_not_know_is_422_unsupported(): void
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);
        $device = $this->philips();

        $this->postJson("/api/devices/{$device->id}/keys", ['key' => 'ok'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->postJson("/api/devices/{$device->id}/keys", ['key' => 'guide'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    public function test_the_api_lists_the_supported_keys_and_presses_one(): void
    {
        $device = $this->remote();

        $this->getJson("/api/devices/{$device->id}/keys")->assertOk()
            ->assertJsonPath('supported_keys', ['up', 'down', 'left', 'right', 'ok', 'back', 'volume_up', 'volume_down', 'red']);
        $this->postJson("/api/devices/{$device->id}/keys", ['key' => 'ok'])->assertOk()->assertJson(['status' => 'ok', 'key' => 'ok']);

        $this->assertSame(['ok'], FakeRemoteDriver::$pressed);
    }

    public function test_the_api_errors(): void
    {
        $device = $this->remote();

        $this->postJson("/api/devices/{$device->id}/keys", ['key' => 'guide'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->postJson("/api/devices/{$device->id}/keys", ['key' => 'banana'])->assertStatus(422)->assertJsonPath('error', 'invalid');
        $this->postJson("/api/devices/{$device->id}/keys", [])->assertStatus(422)->assertJsonValidationErrors('key');
        $this->postJson('/api/devices/'.$this->remote(State::Unreachable)->id.'/keys', ['key' => 'ok'])->assertStatus(503);

        $plain = Device::factory()->driver(FakePlayerDriver::class)->inState(State::Playing)->create();
        $this->postJson("/api/devices/{$plain->id}/keys", ['key' => 'ok'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->getJson("/api/devices/{$plain->id}/keys")->assertStatus(422);
        $this->assertSame([], FakeRemoteDriver::$pressed);
    }

    public function test_a_driver_failure_is_502(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $device = $this->philips();

        $this->postJson("/api/devices/{$device->id}/keys", ['key' => 'ok'])->assertStatus(502)->assertJsonPath('error', 'driver_error');
    }

    public function test_the_detail_lists_supported_keys_only_for_remote_devices(): void
    {
        $this->getJson('/api/devices/'.$this->remote()->id)->assertOk()->assertJsonPath('data.supported_keys.0', 'up');
        $plain = Device::factory()->driver(FakePlayerDriver::class)->inState(State::Playing)->create();

        $this->assertArrayNotHasKey('supported_keys', $this->getJson("/api/devices/{$plain->id}")->json('data'));
    }

    public function test_the_mcp_tool_lists_and_presses(): void
    {
        $device = $this->remote();

        ReMomentServer::tool(Tools\RemoteKeys::class, ['device_id' => $device->id])->assertOk()->assertSee('volume_up');
        ReMomentServer::tool(Tools\RemoteKeys::class, ['device_id' => $device->id, 'key' => 'back'])->assertOk();
        ReMomentServer::tool(Tools\RemoteKeys::class, ['device_id' => $device->id, 'key' => 'guide'])->assertHasErrors(['unsupported (422)']);

        $this->assertSame(['back'], FakeRemoteDriver::$pressed);
    }

    public function test_the_card_draws_only_supported_keys_and_presses_them(): void
    {
        $device = $this->remote();

        Livewire::test(RemoteCard::class, ['device' => $device])
            ->assertSee('Vol +')
            ->assertSee('Back')
            ->assertDontSee('Yellow')
            ->assertDontSee('Home')
            ->call('press', 'ok')
            ->call('press', 'guide');

        $this->assertSame(['ok'], FakeRemoteDriver::$pressed);
    }

    public function test_the_card_is_empty_without_the_capability(): void
    {
        $plain = Device::factory()->driver(FakePlayerDriver::class)->inState(State::Playing)->create();

        Livewire::test(RemoteCard::class, ['device' => $plain])->assertDontSee('Remote');
    }
}
