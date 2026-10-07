<?php

namespace Tests\Feature\Api;

use App\Livewire\ClientManager;
use App\Models\Client;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Contract tests for the client-device API (see docs/api/client-devices.md). */
class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(string $name): Device
    {
        return Device::factory()->create([
            'device_name' => $name,
        ]);
    }

    private function approve(Client $client, string $type = 'multi'): Client
    {
        $client->update([
            'status' => 'approved',
            'type' => $type,
            'api_token' => Client::generateToken(),
            'approved_at' => now(),
        ]);

        return $client->fresh();
    }

    public function test_register_creates_a_pending_client(): void
    {
        $response = $this->postJson('/api/clients/register', [
            'hardware_id' => 'esp-001',
            'firmware_version' => '1.2.0',
            'build_number' => 42,
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['registration_token', 'pairing_code', 'status'])
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('clients', ['hardware_id' => 'esp-001', 'status' => 'pending', 'build_number' => 42]);
    }

    public function test_re_registering_the_same_hardware_returns_the_existing_token(): void
    {
        $first = $this->postJson('/api/clients/register', ['hardware_id' => 'esp-001'])->json('registration_token');

        $this->postJson('/api/clients/register', ['hardware_id' => 'esp-001', 'firmware_version' => '1.3.0'])
            ->assertOk()
            ->assertJsonPath('registration_token', $first);

        $this->assertSame(1, Client::count());
        $this->assertSame('1.3.0', Client::first()->firmware_version);
    }

    public function test_pairing_code_is_short_unambiguous_and_stored(): void
    {
        $code = $this->postJson('/api/clients/register', ['hardware_id' => 'esp-001'])->json('pairing_code');

        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', $code);
        $this->assertSame($code, Client::first()->pairing_code);
    }

    public function test_pairing_code_is_stable_across_re_registration(): void
    {
        $first = $this->postJson('/api/clients/register', ['hardware_id' => 'esp-001'])->json('pairing_code');

        $this->postJson('/api/clients/register', ['hardware_id' => 'esp-001'])
            ->assertOk()
            ->assertJsonPath('pairing_code', $first);
    }

    public function test_re_registering_a_legacy_client_without_code_assigns_one(): void
    {
        Client::create(['hardware_id' => 'esp-old', 'status' => 'pending', 'registration_token' => Client::generateToken()]);

        $code = $this->postJson('/api/clients/register', ['hardware_id' => 'esp-old'])->assertOk()->json('pairing_code');

        $this->assertNotEmpty($code);
        $this->assertSame($code, Client::first()->pairing_code);
    }

    public function test_different_clients_get_different_pairing_codes(): void
    {
        $a = $this->postJson('/api/clients/register', ['hardware_id' => 'esp-a'])->json('pairing_code');
        $b = $this->postJson('/api/clients/register', ['hardware_id' => 'esp-b'])->json('pairing_code');

        $this->assertNotSame($a, $b);
    }

    public function test_admin_ui_shows_pairing_code_for_pending_clients(): void
    {
        $code = $this->postJson('/api/clients/register', ['hardware_id' => 'esp-001'])->json('pairing_code');

        Livewire::test(ClientManager::class)->assertSee($code);
    }

    public function test_register_validates_input(): void
    {
        $this->postJson('/api/clients/register', ['build_number' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('build_number');
    }

    public function test_status_is_pending_until_approved(): void
    {
        $token = $this->postJson('/api/clients/register', ['hardware_id' => 'esp-001'])->json('registration_token');

        $this->getJson("/api/clients/status/{$token}")->assertExactJson(['status' => 'pending']);

        $client = $this->approve(Client::first());
        $device = $this->makeDevice('Kitchen');

        $this->getJson("/api/clients/status/{$token}")
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('type', 'multi')
            ->assertJsonPath('api_token', $client->api_token)
            ->assertJsonPath('devices.0.id', $device->id);
    }

    public function test_unknown_tokens_return_404(): void
    {
        $this->getJson('/api/clients/status/nope')->assertNotFound();
        $this->getJson('/api/clients/nope/devices')->assertNotFound();
        $this->putJson('/api/clients/nope/heartbeat')->assertNotFound();
    }

    public function test_multi_client_without_assignments_sees_all_devices(): void
    {
        $client = $this->approve(Client::create(['status' => 'pending', 'registration_token' => Client::generateToken()]));
        $this->makeDevice('Kitchen');
        $this->makeDevice('Bedroom');

        $this->getJson("/api/clients/{$client->api_token}/devices")
            ->assertOk()
            ->assertJsonCount(2, 'devices')
            ->assertJsonPath('devices.0.device_name', 'Bedroom');

        $this->assertNotNull($client->fresh()->last_seen_at);
    }

    public function test_single_client_sees_only_its_assigned_device(): void
    {
        $client = $this->approve(Client::create(['status' => 'pending', 'registration_token' => Client::generateToken()]), 'single');
        $this->makeDevice('Kitchen');
        $bedroom = $this->makeDevice('Bedroom');
        $client->devices()->attach($bedroom->id, ['sort_order' => 0]);

        $this->getJson("/api/clients/{$client->api_token}/devices")
            ->assertJsonCount(1, 'devices')
            ->assertJsonPath('devices.0.id', $bedroom->id);
    }

    public function test_heartbeat_updates_firmware_and_last_seen(): void
    {
        $client = $this->approve(Client::create(['status' => 'pending', 'registration_token' => Client::generateToken()]));

        $this->putJson("/api/clients/{$client->api_token}/heartbeat", ['firmware_version' => '2.0.0', 'build_number' => 7])
            ->assertExactJson(['status' => 'ok']);

        $client->refresh();
        $this->assertSame('2.0.0', $client->firmware_version);
        $this->assertSame(7, (int) $client->build_number);
        $this->assertNotNull($client->last_seen_at);
    }

    public function test_heartbeat_stores_battery_state(): void
    {
        $client = $this->approve(Client::create(['status' => 'pending', 'registration_token' => Client::generateToken()]));

        $this->putJson("/api/clients/{$client->api_token}/heartbeat", ['battery_percent' => 15, 'battery_charging' => true])
            ->assertOk();

        $client->refresh();
        $this->assertSame(15, $client->battery_percent);
        $this->assertTrue($client->battery_charging);

        $this->putJson("/api/clients/{$client->api_token}/heartbeat", ['firmware_version' => '2.0.1'])->assertOk();
        $this->assertSame(15, $client->fresh()->battery_percent);
    }

    public function test_heartbeat_rejects_out_of_range_battery(): void
    {
        $client = $this->approve(Client::create(['status' => 'pending', 'registration_token' => Client::generateToken()]));

        $this->putJson("/api/clients/{$client->api_token}/heartbeat", ['battery_percent' => 101])
            ->assertUnprocessable();
    }
}
