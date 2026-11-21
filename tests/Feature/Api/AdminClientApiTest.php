<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** /api/admin/clients (docs/api/admin-clients.md): the actions of /settings/clients for apps. */
class AdminClientApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsAdmin;

    private function client(string $status = 'pending', array $extra = []): Client
    {
        return Client::create($extra + [
            'name' => 'ESP32 desk', 'type' => 'single', 'kind' => 'client', 'status' => $status, 'hardware_id' => 'hw'.uniqid(),
            'registration_token' => Client::generateToken(), 'pairing_code' => Client::generatePairingCode(),
            'api_token' => $status === 'approved' ? Client::generateToken() : null,
        ]);
    }

    public function test_every_route_needs_the_admin_token(): void
    {
        $client = $this->client();

        $this->assertNeedsAdminToken([
            ['GET', '/api/admin/clients'], ['GET', "/api/admin/clients/{$client->id}"], ['PUT', "/api/admin/clients/{$client->id}"],
            ['DELETE', "/api/admin/clients/{$client->id}"], ['POST', "/api/admin/clients/{$client->id}/approve"],
            ['POST', "/api/admin/clients/{$client->id}/reject"], ['POST', "/api/admin/clients/{$client->id}/regenerate-token"],
        ]);
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_the_list_has_pending_first_and_never_shows_a_token(): void
    {
        $approved = $this->client('approved', ['name' => 'Old']);
        $pending = $this->client('pending', ['name' => 'New']);

        $response = $this->getJson('/api/admin/clients', $this->adminHeaders())->assertOk();

        $response->assertJsonPath('pending', 1)->assertJsonPath('data.0.id', $pending->id)->assertJsonPath('data.1.id', $approved->id);
        $response->assertJsonPath('data.0.pairing_code', $pending->pairing_code)->assertJsonPath('data.1.pairing_code', null);
        $response->assertJsonStructure(['data' => [['id', 'name', 'kind', 'type', 'status', 'pairing_code', 'hardware_id', 'ip_address', 'firmware_version', 'build_number', 'battery', 'last_seen_at', 'approved_at', 'created_at', 'devices', 'hidden_sources', 'roles', 'enabled_roles']]]);
        $this->assertStringNotContainsString((string) $approved->api_token, $response->getContent());

        $this->getJson('/api/admin/clients?status=approved', $this->adminHeaders())->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $approved->id);
        $this->getJson('/api/admin/clients?status=x', $this->adminHeaders())->assertStatus(422);
    }

    public function test_the_detail_shows_the_token(): void
    {
        $client = $this->client('approved');

        $this->getJson("/api/admin/clients/{$client->id}", $this->adminHeaders())->assertOk()->assertJsonPath('data.api_token', $client->api_token);
        $this->getJson('/api/admin/clients/999', $this->adminHeaders())->assertNotFound();
    }

    public function test_approving_issues_the_token_and_the_client_can_collect_it(): void
    {
        $client = $this->client();

        $response = $this->postJson("/api/admin/clients/{$client->id}/approve", ['type' => 'multi', 'name' => '  Hall panel '], $this->adminHeaders())->assertOk();

        $response->assertJsonPath('data.status', 'approved')->assertJsonPath('data.type', 'multi')->assertJsonPath('data.name', 'Hall panel')->assertJsonPath('data.pairing_code', null);
        $token = $response->json('data.api_token');
        $this->assertSame(48, strlen($token));
        $this->getJson("/api/clients/status/{$client->registration_token}")->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('api_token', $token);
    }

    public function test_approve_and_reject_only_work_on_a_pending_registration(): void
    {
        $approved = $this->client('approved');

        $this->postJson("/api/admin/clients/{$approved->id}/approve", [], $this->adminHeaders())->assertStatus(409)->assertJsonPath('error', 'not_pending');
        $this->postJson("/api/admin/clients/{$approved->id}/reject", [], $this->adminHeaders())->assertStatus(409)->assertJsonPath('error', 'not_pending');
        $this->postJson('/api/admin/clients/'.$this->client()->id.'/approve', ['type' => 'both'], $this->adminHeaders())->assertStatus(422);
    }

    public function test_rejecting_removes_the_registration(): void
    {
        $client = $this->client();

        $this->postJson("/api/admin/clients/{$client->id}/reject", [], $this->adminHeaders())->assertOk()->assertJsonPath('status', 'ok');
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_a_node_is_approved_with_the_roles_the_admin_picks_within_what_it_declared(): void
    {
        $node = $this->client('pending', ['kind' => 'node', 'roles' => ['line-in', 'ripper']]);

        $response = $this->postJson("/api/admin/clients/{$node->id}/approve", ['roles' => ['line-in', 'player']], $this->adminHeaders())->assertOk();

        $response->assertJsonPath('data.roles', ['line-in', 'ripper'])->assertJsonPath('data.enabled_roles', ['line-in']);
        $this->postJson('/api/admin/clients/'.$this->client('pending', ['kind' => 'node'])->id.'/approve', ['roles' => ['nope']], $this->adminHeaders())->assertStatus(422);
    }

    public function test_editing_changes_only_what_is_sent(): void
    {
        $client = $this->client('approved', ['type' => 'multi', 'hidden_sources' => ['dlna']]);
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        $client->devices()->sync([$a->id, $b->id]);

        $this->putJson("/api/admin/clients/{$client->id}", ['name' => 'Renamed'], $this->adminHeaders())
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.type', 'multi')->assertJsonCount(2, 'data.devices')->assertJsonPath('data.hidden_sources', ['dlna']);

        $this->putJson("/api/admin/clients/{$client->id}", ['device_ids' => [$b->id], 'hidden_sources' => []], $this->adminHeaders())
            ->assertOk()->assertJsonPath('data.devices.0.id', $b->id)->assertJsonPath('data.hidden_sources', []);
    }

    public function test_a_single_client_keeps_at_most_one_device(): void
    {
        $client = $this->client('approved', ['type' => 'multi']);
        $a = Device::factory()->create();
        $b = Device::factory()->create();

        $this->putJson("/api/admin/clients/{$client->id}", ['type' => 'single', 'device_ids' => [$a->id, $b->id]], $this->adminHeaders())
            ->assertOk()->assertJsonCount(1, 'data.devices')->assertJsonPath('data.devices.0.id', $a->id);
    }

    public function test_editing_validates_devices_types_and_sources(): void
    {
        $client = $this->client('approved');

        $this->putJson("/api/admin/clients/{$client->id}", ['device_ids' => [999], 'type' => 'x', 'hidden_sources' => ['nope']], $this->adminHeaders())
            ->assertStatus(422)->assertJsonValidationErrors(['device_ids.0', 'type', 'hidden_sources.0']);
    }

    public function test_regenerating_the_token_replaces_it(): void
    {
        $client = $this->client('approved');
        $old = $client->api_token;

        $new = $this->postJson("/api/admin/clients/{$client->id}/regenerate-token", [], $this->adminHeaders())->assertOk()->json('data.api_token');

        $this->assertNotSame($old, $new);
        $this->getJson("/api/clients/{$old}/devices")->assertNotFound();
        $this->getJson("/api/clients/{$new}/devices")->assertOk();
        $this->postJson('/api/admin/clients/'.$this->client()->id.'/regenerate-token', [], $this->adminHeaders())->assertStatus(409)->assertJsonPath('error', 'not_approved');
    }

    public function test_deleting_removes_the_client(): void
    {
        $client = $this->client('approved');

        $this->deleteJson("/api/admin/clients/{$client->id}", [], $this->adminHeaders())->assertOk();
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }
}
