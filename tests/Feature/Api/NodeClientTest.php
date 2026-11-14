<?php

namespace Tests\Feature\Api;

use App\Livewire\ClientManager;
use App\Models\Client;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Node clients (workstream 18, docs/architecture/node-agent.md): kind, roles, capabilities, heartbeat status. */
class NodeClientTest extends TestCase
{
    use RefreshDatabase;

    private function registerNode(array $extra = []): void
    {
        $this->postJson('/api/clients/register', array_merge([
            'kind' => 'node',
            'hardware_id' => 'node-machine-1',
            'roles' => ['ripper', 'line-in'],
            'capabilities' => ['ripper' => ['drive' => '/dev/sr0']],
        ], $extra))->assertSuccessful();
    }

    private function approve(Client $client, array $enabled): Client
    {
        $client->update(['status' => 'approved', 'api_token' => Client::generateToken(), 'enabled_roles' => $enabled]);

        return $client->fresh();
    }

    public function test_a_client_without_the_new_fields_is_unchanged(): void
    {
        $this->postJson('/api/clients/register', ['hardware_id' => 'esp-1'])->assertCreated();

        $client = Client::first();
        $this->assertSame('client', $client->kind);
        $this->assertNull($client->roles);
        $this->assertFalse($client->isNode());

        $client = $this->approve($client, []);
        $this->getJson('/api/clients/status/'.$client->registration_token)->assertJsonMissingPath('roles')->assertJsonMissingPath('kind');
        $this->putJson("/api/clients/{$client->api_token}/heartbeat", ['status' => ['uptime_seconds' => 5]])->assertOk()->assertExactJson(['status' => 'ok']);
        $this->assertNull($client->fresh()->node_status);
    }

    public function test_register_stores_kind_roles_and_capabilities(): void
    {
        $this->postJson('/api/clients/register', [
            'kind' => 'node', 'hardware_id' => 'n1', 'roles' => ['line-in', 'ripper', 'ripper'],
            'capabilities' => ['ripper' => ['drive' => '/dev/sr0']],
        ])->assertCreated()->assertJsonPath('status', 'pending');

        $client = Client::first();
        $this->assertTrue($client->isNode());
        $this->assertSame(['ripper', 'line-in'], $client->roles);
        $this->assertSame(['ripper' => ['drive' => '/dev/sr0']], $client->capabilities);
        $this->assertNull($client->enabled_roles);
    }

    public function test_unknown_role_kind_and_oversized_capabilities_are_refused(): void
    {
        $this->postJson('/api/clients/register', ['kind' => 'node', 'hardware_id' => 'n', 'roles' => ['shell']])->assertStatus(422);
        $this->postJson('/api/clients/register', ['kind' => 'robot', 'hardware_id' => 'n'])->assertStatus(422);
        $this->postJson('/api/clients/register', ['kind' => 'node', 'hardware_id' => 'n', 'capabilities' => ['x' => str_repeat('a', 17000)]])
            ->assertStatus(422)->assertJsonPath('error', 'too_large');
        $this->postJson('/api/clients/register', ['kind' => 'node', 'roles' => ['ripper']])
            ->assertStatus(422)->assertJsonPath('error', 'hardware_id_required');
        $this->assertSame(0, Client::count());
    }

    public function test_roles_and_capabilities_of_a_plain_client_are_ignored(): void
    {
        $this->postJson('/api/clients/register', ['hardware_id' => 'c', 'roles' => ['ripper'], 'capabilities' => ['a' => 1]])->assertCreated();

        $client = Client::first();
        $this->assertNull($client->roles);
        $this->assertNull($client->capabilities);
    }

    public function test_re_registering_keeps_only_enabled_roles_that_are_still_declared(): void
    {
        $this->registerNode();
        $client = $this->approve(Client::first(), ['ripper', 'line-in']);

        $this->postJson('/api/clients/register', ['kind' => 'node', 'hardware_id' => 'node-machine-1', 'roles' => ['ripper', 'player']])
            ->assertOk()->assertJsonPath('status', 'approved');

        $client = $client->fresh();
        $this->assertSame(['ripper', 'player'], $client->roles);
        $this->assertSame(['ripper'], $client->enabled_roles);
        $this->assertSame(['ripper'], $client->activeRoles());
    }

    public function test_the_kind_of_a_known_hardware_id_cannot_change(): void
    {
        $this->postJson('/api/clients/register', ['hardware_id' => 'same'])->assertCreated();
        $this->postJson('/api/clients/register', ['hardware_id' => 'same', 'kind' => 'node'])->assertStatus(422)->assertJsonPath('error', 'kind_mismatch');
        $this->assertSame('client', Client::first()->kind);
    }

    public function test_status_and_heartbeat_tell_a_node_its_enabled_roles(): void
    {
        $this->registerNode();
        $client = $this->approve(Client::first(), ['line-in', 'player']); // player was not declared

        $this->getJson('/api/clients/status/'.$client->registration_token)
            ->assertOk()->assertJsonPath('kind', 'node')->assertJsonPath('client_id', $client->id)->assertJsonPath('roles', ['line-in']);

        $this->putJson("/api/clients/{$client->api_token}/heartbeat", [])
            ->assertOk()->assertJsonPath('roles', ['line-in']);
    }

    public function test_heartbeat_stores_the_node_status_for_known_roles_only(): void
    {
        $this->registerNode();
        $client = $this->approve(Client::first(), ['ripper']);

        $this->putJson("/api/clients/{$client->api_token}/heartbeat", ['status' => [
            'roles' => ['ripper' => ['state' => 'busy', 'detail' => 'track 3 of 12'], 'rm-rf' => ['state' => 'idle']],
            'uptime_seconds' => 3600, 'load' => 0.4, 'disk_free_bytes' => 1000, 'evil' => 'x',
        ]])->assertOk();

        $this->assertSame([
            'roles' => ['ripper' => ['state' => 'busy', 'detail' => 'track 3 of 12']],
            'uptime_seconds' => 3600, 'load' => 0.4, 'disk_free_bytes' => 1000,
        ], $client->fresh()->node_status);

        $this->putJson("/api/clients/{$client->api_token}/heartbeat", ['status' => ['roles' => ['ripper' => ['state' => 'flying']]]])->assertStatus(422);
    }

    public function test_a_node_never_gets_the_all_devices_rule(): void
    {
        Device::factory()->create();
        $this->registerNode();
        $client = $this->approve(Client::first(), []);
        $client->update(['type' => 'multi']);

        $this->getJson("/api/clients/{$client->api_token}/devices")->assertOk()->assertJsonCount(0, 'devices');
    }

    public function test_admin_approval_switches_on_chosen_declared_roles(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        $this->registerNode();
        $client = Client::first();

        Livewire::test(ClientManager::class)
            ->call('startApprove', $client->id)
            ->assertSet('approveRoles', ['ripper', 'line-in'])
            ->set('approveRoles', ['ripper', 'player'])
            ->call('approve');

        $client = $client->fresh();
        $this->assertSame('approved', $client->status);
        $this->assertSame(['ripper'], $client->enabled_roles);
    }

    public function test_admin_edit_changes_roles_and_the_list_shows_the_node_badge(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        $this->registerNode();
        $client = $this->approve(Client::first(), ['ripper']);

        Livewire::test(ClientManager::class)
            ->assertSee('node')->assertSee('CD ripper')->assertSee('Line-in (off)')
            ->call('startEdit', $client->id)
            ->assertSet('editRoles', ['ripper'])
            ->set('editRoles', ['line-in'])
            ->call('saveEdit');

        $this->assertSame(['line-in'], $client->fresh()->enabled_roles);
    }
}
