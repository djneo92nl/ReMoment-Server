<?php

namespace Tests\Feature\Api;

use App\Domain\Device\DeviceAdmin;
use App\Domain\Device\DeviceScan;
use App\Domain\Device\DiscoveredDevice;
use App\Jobs\ScanForDevices;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\DiscoveredDeviceRecord;
use App\Services\NetworkDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** /api/admin/devices, /api/admin/device-models and /api/admin/discovery (docs/api/admin-devices.md). */
class AdminDeviceApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsAdmin;

    /** @return array{0: string, 1: string} a model with a network address */
    private function physical(): array
    {
        $model = collect(DeviceAdmin::models())->first(fn ($m) => !$m['virtual']);

        return [$model['brand'], $model['product']];
    }

    private function record(string $ip = '10.0.0.5', string $name = 'Kitchen'): DiscoveredDeviceRecord
    {
        return DiscoveredDeviceRecord::create([
            'ip_address' => $ip, 'device_name' => $name, 'device_brand_name' => 'Sonos', 'device_product_type' => 'Era 100',
            'device_driver' => 'App\Fake\Driver', 'device_driver_name' => 'Fake', 'meta' => ['sonos_uuid' => 'RINCON_'.$ip], 'last_seen_at' => now(),
        ]);
    }

    public function test_every_route_needs_the_admin_token(): void
    {
        $device = Device::factory()->create();
        $record = $this->record();

        $this->assertNeedsAdminToken([
            ['GET', '/api/admin/device-models'], ['GET', '/api/admin/devices'], ['POST', '/api/admin/devices'],
            ['GET', "/api/admin/devices/{$device->id}"], ['PUT', "/api/admin/devices/{$device->id}"], ['DELETE', "/api/admin/devices/{$device->id}"],
            ['GET', '/api/admin/discovery'], ['POST', '/api/admin/discovery/scan'], ['POST', '/api/admin/discovery/add-all'],
            ['POST', "/api/admin/discovery/{$record->id}/add"], ['POST', "/api/admin/discovery/{$record->id}/ignore"], ['POST', "/api/admin/discovery/{$record->id}/restore"],
        ]);
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('discovered_devices', 1);
    }

    public function test_the_models_list_what_a_device_can_be_added_as(): void
    {
        $response = $this->getJson('/api/admin/device-models', $this->adminHeaders())->assertOk();

        $response->assertJsonStructure(['data' => [['brand', 'product', 'driver_name', 'virtual']]]);
        $this->assertContains(true, array_column($response->json('data'), 'virtual'), 'Spotify is a virtual model');
        $this->assertContains(false, array_column($response->json('data'), 'virtual'));
    }

    public function test_adding_a_device_resolves_the_driver_from_the_model(): void
    {
        [$brand, $product] = $this->physical();

        $response = $this->postJson('/api/admin/devices', [
            'device_name' => 'Living room', 'ip_address' => '192.168.1.40', 'device_brand_name' => $brand, 'device_product_type' => $product,
        ], $this->adminHeaders())->assertCreated();

        $response->assertJsonPath('data.device_name', 'Living room')->assertJsonPath('data.hidden', false)->assertJsonPath('data.ip_address', '192.168.1.40');
        $device = Device::findOrFail($response->json('data.id'));
        $this->assertSame(config("devices.{$brand}.{$product}.driver"), $device->device_driver);
        $this->assertSame($brand, $device->device_brand_name);
    }

    public function test_a_virtual_model_needs_no_address_and_a_physical_one_does(): void
    {
        $virtual = collect(DeviceAdmin::models())->first(fn ($m) => $m['virtual']);
        [$brand, $product] = $this->physical();

        $this->postJson('/api/admin/devices', ['device_name' => 'Spotify', 'device_brand_name' => $virtual['brand'], 'device_product_type' => $virtual['product']], $this->adminHeaders())->assertCreated();
        $this->postJson('/api/admin/devices', ['device_name' => 'Box', 'device_brand_name' => $brand, 'device_product_type' => $product], $this->adminHeaders())
            ->assertStatus(422)->assertJsonValidationErrors(['ip_address']);
    }

    public function test_an_unknown_model_is_refused(): void
    {
        $this->postJson('/api/admin/devices', ['device_name' => 'X', 'ip_address' => '10.0.0.1', 'device_brand_name' => 'Nope', 'device_product_type' => 'Nada'], $this->adminHeaders())
            ->assertStatus(422)->assertJsonValidationErrors(['device_product_type']);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_the_list_includes_hidden_devices(): void
    {
        Device::factory()->create(['device_name' => 'Shown']);
        Device::factory()->create(['device_name' => 'Hidden', 'hidden' => true]);

        $response = $this->getJson('/api/admin/devices', $this->adminHeaders())->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame([true], array_values(array_unique(array_column(array_filter($response->json('data'), fn ($d) => $d['device_name'] === 'Hidden'), 'hidden'))));
        $this->getJson('/api/admin/devices/'.Device::first()->id, $this->adminHeaders())->assertOk()->assertJsonStructure(['data' => ['id', 'device_name', 'capabilities', 'hidden']]);
        $this->getJson('/api/admin/devices/999', $this->adminHeaders())->assertNotFound();
    }

    public function test_editing_changes_name_address_and_hidden(): void
    {
        $device = Device::factory()->create(['device_name' => 'Old', 'ip_address' => '10.0.0.1']);

        $this->putJson("/api/admin/devices/{$device->id}", ['device_name' => 'New', 'ip_address' => '10.0.0.2', 'hidden' => true], $this->adminHeaders())
            ->assertOk()->assertJsonPath('data.device_name', 'New')->assertJsonPath('data.ip_address', '10.0.0.2')->assertJsonPath('data.hidden', true);
        $this->assertTrue($device->refresh()->hidden);

        $this->putJson("/api/admin/devices/{$device->id}", ['hidden' => false], $this->adminHeaders())->assertOk()->assertJsonPath('data.device_name', 'New')->assertJsonPath('data.hidden', false);
    }

    public function test_changing_the_model_picks_the_driver_again_and_an_address_cannot_be_cleared(): void
    {
        [$brand, $product] = $this->physical();
        $device = Device::factory()->create();

        $this->putJson("/api/admin/devices/{$device->id}", ['device_brand_name' => $brand], $this->adminHeaders())->assertStatus(422)->assertJsonValidationErrors(['device_product_type']);
        $this->putJson("/api/admin/devices/{$device->id}", ['device_brand_name' => $brand, 'device_product_type' => $product], $this->adminHeaders())->assertOk();
        $this->assertSame(config("devices.{$brand}.{$product}.driver"), $device->refresh()->device_driver);

        $this->putJson("/api/admin/devices/{$device->id}", ['ip_address' => null], $this->adminHeaders())->assertStatus(422)->assertJsonValidationErrors(['ip_address']);
    }

    public function test_deleting_removes_the_device_and_its_meta(): void
    {
        $device = Device::factory()->create();
        DeviceMeta::create(['device_id' => $device->id, 'key' => 'k', 'value' => 'v']);

        $this->deleteJson("/api/admin/devices/{$device->id}", [], $this->adminHeaders())->assertOk()->assertJsonPath('status', 'ok');

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
        $this->assertDatabaseMissing('device_meta', ['device_id' => $device->id]);
    }

    public function test_the_inbox_lists_finds_and_ignored_ones_apart(): void
    {
        Cache::flush();
        $this->record('10.0.0.5', 'Kitchen');
        $ignored = $this->record('10.0.0.6', 'Garage');
        $ignored->update(['ignored_at' => now()]);

        $response = $this->getJson('/api/admin/discovery', $this->adminHeaders())->assertOk();

        $response->assertJsonPath('scan.state', 'idle')->assertJsonCount(1, 'data')->assertJsonPath('data.0.device_name', 'Kitchen')->assertJsonPath('ignored.0.device_name', 'Garage')->assertJsonPath('ignored.0.ignored', true);
        $response->assertJsonStructure(['data' => [['id', 'device_name', 'ip_address', 'device_brand_name', 'device_product_type', 'device_driver_name', 'last_seen_at', 'ignored']]]);
    }

    public function test_a_scan_is_queued_once_and_its_result_is_polled(): void
    {
        Cache::flush();
        Queue::fake();

        $this->postJson('/api/admin/discovery/scan', ['hosts' => ['192.168.1.9', 'not a host']], $this->adminHeaders())
            ->assertStatus(202)->assertJsonPath('scan.state', 'running');
        Queue::assertPushed(ScanForDevices::class, fn ($job) => $job->hosts === ['192.168.1.9']);

        // A second request while one runs does not queue another.
        $this->postJson('/api/admin/discovery/scan', [], $this->adminHeaders())->assertStatus(202)->assertJsonPath('scan.state', 'running');
        Queue::assertPushed(ScanForDevices::class, 1);
    }

    public function test_the_scan_job_records_the_finds_and_reports_the_summary(): void
    {
        Cache::flush();
        $this->app->instance(NetworkDiscoveryService::class, new class extends NetworkDiscoveryService
        {
            public function __construct() {}

            public function discoverAll(?array $discovererClasses = null, array $hosts = []): array
            {
                return ['10.0.0.7' => new DiscoveredDevice('10.0.0.7', 'Office', 'Sonos', 'Era 100', 'App\Fake\Driver', 'Fake', ['sonos_uuid' => 'RINCON_7'])];
            }
        });

        DeviceScan::start();   // the sync queue of the tests runs the job at once

        $this->getJson('/api/admin/discovery', $this->adminHeaders())->assertOk()
            ->assertJsonPath('scan.state', 'done')->assertJsonPath('scan.result.new', 1)->assertJsonPath('scan.result.summary', '1 new, 0 already added.')
            ->assertJsonPath('data.0.device_name', 'Office');
    }

    public function test_a_failing_scan_is_reported_as_failed(): void
    {
        Cache::flush();
        $this->app->instance(NetworkDiscoveryService::class, new class extends NetworkDiscoveryService
        {
            public function __construct() {}

            public function discoverAll(?array $discovererClasses = null, array $hosts = []): array
            {
                throw new \RuntimeException('socket error');
            }
        });

        DeviceScan::start();

        $this->getJson('/api/admin/discovery', $this->adminHeaders())->assertJsonPath('scan.state', 'failed')->assertJsonPath('scan.error', 'socket error');
    }

    public function test_adding_a_find_makes_a_device_and_empties_the_inbox_row(): void
    {
        $record = $this->record();

        $response = $this->postJson("/api/admin/discovery/{$record->id}/add", ['name' => ' Den '], $this->adminHeaders())->assertCreated();

        $response->assertJsonPath('data.device_name', 'Den')->assertJsonPath('data.ip_address', '10.0.0.5');
        $this->assertDatabaseHas('devices', ['device_name' => 'Den']);
        $this->assertDatabaseMissing('discovered_devices', ['id' => $record->id]);
        $this->postJson("/api/admin/discovery/{$record->id}/add", [], $this->adminHeaders())->assertNotFound();
    }

    public function test_add_all_skips_ignored_finds(): void
    {
        $this->record('10.0.0.5', 'A');
        $this->record('10.0.0.6', 'B');
        $this->record('10.0.0.7', 'C')->update(['ignored_at' => now()]);

        $this->postJson('/api/admin/discovery/add-all', [], $this->adminHeaders())->assertOk()->assertJsonPath('added', 2)->assertJsonCount(2, 'device_ids');
        $this->assertDatabaseCount('devices', 2);
        $this->assertDatabaseCount('discovered_devices', 1);
    }

    public function test_ignore_and_restore(): void
    {
        $record = $this->record();

        $this->postJson("/api/admin/discovery/{$record->id}/ignore", [], $this->adminHeaders())->assertOk()->assertJsonPath('data.ignored', true);
        $this->assertNotNull($record->refresh()->ignored_at);

        $this->postJson("/api/admin/discovery/{$record->id}/restore", [], $this->adminHeaders())->assertOk()->assertJsonPath('data.ignored', false);
        $this->assertNull($record->refresh()->ignored_at);
    }
}
