<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\AdminToken;
use App\Models\Client;
use App\Models\Device;
use App\Models\DiscoveredDeviceRecord;
use App\Models\MultiroomPreset;
use App\Models\RadioStation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** The MCP tools of the admin areas (health, clients, presets, radio stations, devices); they only call the REST API. */
class AdminAreasMcpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        FakePlayerDriver::reset();
        parent::tearDown();
    }

    private function asAdmin(): void
    {
        User::factory()->create();
        [, $plain] = AdminToken::issue('test');
        request()->headers->set('Authorization', 'Bearer '.$plain);
    }

    public function test_the_admin_tools_are_only_listed_with_the_token(): void
    {
        foreach ([[Tools\ServerHealth::class, ['action' => 'summary']], [Tools\Clients::class, ['action' => 'list']], [Tools\RadioStations::class, ['action' => 'list']], [Tools\ManageDevices::class, ['action' => 'list']]] as [$tool, $args]) {
            ReMomentServer::tool($tool, $args)->assertNotRegistered();
        }
        // Presets are open, like the REST routes.
        ReMomentServer::tool(Tools\MultiroomPresets::class, ['action' => 'list'])->assertOk();
    }

    public function test_server_health(): void
    {
        $this->asAdmin();

        ReMomentServer::tool(Tools\ServerHealth::class, ['action' => 'summary'])->assertOk()->assertSee('scheduler')->assertSee('queue_worker');
        ReMomentServer::tool(Tools\ServerHealth::class, ['action' => 'services'])->assertOk()->assertSee('problems');
        ReMomentServer::tool(Tools\ServerHealth::class, ['action' => 'check_services', 'only' => ['nope']])->assertHasErrors(['http_422']);
    }

    public function test_clients(): void
    {
        $this->asAdmin();
        $client = Client::create(['name' => 'Panel', 'type' => 'single', 'kind' => 'client', 'status' => 'pending', 'hardware_id' => 'hw', 'registration_token' => 'rt', 'pairing_code' => 'ABC234']);

        ReMomentServer::tool(Tools\Clients::class, ['action' => 'list'])->assertOk()->assertSee('ABC234');
        ReMomentServer::tool(Tools\Clients::class, ['action' => 'approve'])->assertHasErrors(['needs id']);
        ReMomentServer::tool(Tools\Clients::class, ['action' => 'approve', 'id' => $client->id, 'type' => 'multi', 'name' => 'Hall'])->assertOk()->assertSee('api_token');
        $this->assertSame('approved', $client->refresh()->status);
        ReMomentServer::tool(Tools\Clients::class, ['action' => 'update', 'id' => $client->id, 'name' => 'Hallway'])->assertOk();
        $this->assertSame('Hallway', $client->refresh()->name);
        ReMomentServer::tool(Tools\Clients::class, ['action' => 'regenerate_token', 'id' => $client->id])->assertOk();
        ReMomentServer::tool(Tools\Clients::class, ['action' => 'delete', 'id' => $client->id])->assertHasErrors(['confirmation_required']);
        ReMomentServer::tool(Tools\Clients::class, ['action' => 'delete', 'id' => $client->id, 'confirm' => true])->assertOk();
        $this->assertSame(0, Client::count());
    }

    public function test_multiroom_presets(): void
    {
        $a = Device::factory()->create(['device_name' => 'A']);
        $b = Device::factory()->create(['device_name' => 'B']);

        ReMomentServer::tool(Tools\MultiroomPresets::class, ['action' => 'create', 'name' => 'Both', 'device_ids' => [$a->id, $b->id]])->assertOk()->assertSee('Both');
        $preset = MultiroomPreset::firstOrFail();
        ReMomentServer::tool(Tools\MultiroomPresets::class, ['action' => 'create', 'name' => 'One', 'device_ids' => [$a->id]])->assertHasErrors(['http_422']);
        ReMomentServer::tool(Tools\MultiroomPresets::class, ['action' => 'activate', 'id' => $preset->id])->assertOk()->assertSee('joined');
        $this->assertSame([['joinSession', $a->id]], FakePlayerDriver::$calls);
        ReMomentServer::tool(Tools\MultiroomPresets::class, ['action' => 'update', 'id' => $preset->id, 'name' => 'Pair'])->assertOk();
        ReMomentServer::tool(Tools\MultiroomPresets::class, ['action' => 'delete'])->assertHasErrors(['needs id']);
        ReMomentServer::tool(Tools\MultiroomPresets::class, ['action' => 'delete', 'id' => $preset->id])->assertOk();
        $this->assertSame(0, MultiroomPreset::count());
    }

    public function test_radio_stations(): void
    {
        $this->asAdmin();

        ReMomentServer::tool(Tools\RadioStations::class, ['action' => 'add', 'name' => 'Jazz FM', 'identifiers' => ['url' => 'https://stream.test/jazz.mp3']])->assertOk()->assertSee('Jazz FM');
        $station = RadioStation::firstOrFail();
        ReMomentServer::tool(Tools\RadioStations::class, ['action' => 'update', 'id' => $station->id, 'genre' => 'Jazz'])->assertOk();
        $this->assertSame('Jazz', $station->refresh()->genre);
        ReMomentServer::tool(Tools\RadioStations::class, ['action' => 'search'])->assertHasErrors(['needs q']);
        ReMomentServer::tool(Tools\RadioStations::class, ['action' => 'remove', 'id' => $station->id])->assertHasErrors(['confirmation_required']);
        ReMomentServer::tool(Tools\RadioStations::class, ['action' => 'remove', 'id' => $station->id, 'confirm' => true])->assertOk();
        $this->assertSame(0, RadioStation::count());
    }

    public function test_manage_devices(): void
    {
        $this->asAdmin();
        $model = collect(\App\Domain\Device\DeviceAdmin::models())->first(fn ($m) => !$m['virtual']);

        ReMomentServer::tool(Tools\ManageDevices::class, ['action' => 'models'])->assertOk()->assertSee($model['product']);
        ReMomentServer::tool(Tools\ManageDevices::class, ['action' => 'add', 'device_name' => 'Den', 'ip_address' => '10.0.0.9', 'device_brand_name' => $model['brand'], 'device_product_type' => $model['product']])->assertOk()->assertSee('Den');
        $device = Device::firstOrFail();
        ReMomentServer::tool(Tools\ManageDevices::class, ['action' => 'update', 'id' => $device->id, 'hidden' => true])->assertOk();
        $this->assertTrue($device->refresh()->hidden);

        $found = DiscoveredDeviceRecord::create(['ip_address' => '10.0.0.20', 'device_name' => 'Found', 'device_brand_name' => 'Sonos', 'device_product_type' => 'Era 100', 'device_driver' => 'App\Fake\Driver', 'device_driver_name' => 'Fake', 'meta' => [], 'last_seen_at' => now()]);
        ReMomentServer::tool(Tools\ManageDevices::class, ['action' => 'found'])->assertOk()->assertSee('Found');
        ReMomentServer::tool(Tools\ManageDevices::class, ['action' => 'add_found'])->assertHasErrors(['needs found_id']);
        ReMomentServer::tool(Tools\ManageDevices::class, ['action' => 'ignore_found', 'found_id' => $found->id])->assertOk();
        ReMomentServer::tool(Tools\ManageDevices::class, ['action' => 'restore_found', 'found_id' => $found->id])->assertOk();
        ReMomentServer::tool(Tools\ManageDevices::class, ['action' => 'add_found', 'found_id' => $found->id, 'name' => 'Added'])->assertOk()->assertSee('Added');
        ReMomentServer::tool(Tools\ManageDevices::class, ['action' => 'scan'])->assertOk()->assertSee('running');

        ReMomentServer::tool(Tools\ManageDevices::class, ['action' => 'remove', 'id' => $device->id])->assertHasErrors(['confirmation_required']);
        ReMomentServer::tool(Tools\ManageDevices::class, ['action' => 'remove', 'id' => $device->id, 'confirm' => true])->assertOk();
        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }
}
