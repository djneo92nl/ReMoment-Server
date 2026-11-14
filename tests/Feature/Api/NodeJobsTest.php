<?php

namespace Tests\Feature\Api;

use App\Domain\Alerts\AlertChecks;
use App\Domain\Alerts\Alerts;
use App\Models\Client;
use App\Models\NodeJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Jobs for nodes (docs/architecture/node-agent.md section 4): REST lifecycle, MQTT only nudges. */
class NodeJobsTest extends TestCase
{
    use RefreshDatabase;

    private function node(array $roles = [], array $enabled = []): Client
    {
        return Client::create([
            'kind' => 'node', 'status' => 'approved', 'hardware_id' => 'n'.uniqid(), 'registration_token' => Client::generateToken(),
            'api_token' => Client::generateToken(), 'roles' => $roles, 'enabled_roles' => $enabled, 'last_seen_at' => now(),
        ]);
    }

    private function bearer(Client $c): array
    {
        return ['Authorization' => 'Bearer '.$c->api_token];
    }

    private function adminHeaders(): array
    {
        RateLimiter::clear('admin-api-login|127.0.0.1');
        User::factory()->create();

        return ['Authorization' => 'Bearer '.$this->postJson('/api/admin/login', ['password' => 'password'])->json('token')];
    }

    public function test_the_admin_creates_a_ping_and_the_node_is_nudged_with_the_id_only(): void
    {
        $node = $this->node();

        $id = $this->postJson("/api/admin/nodes/{$node->id}/jobs", ['type' => 'ping', 'params' => ['message' => 'hi']], $this->adminHeaders())
            ->assertCreated()->assertJsonPath('job.state', 'queued')->json('job.id');

        $published = collect($this->mqtt->published ?? [])->first(fn ($m) => ($m['topic'] ?? null) === "remoment/node/{$node->id}/cmd/job");
        $this->assertNotNull($published);
        $this->assertSame(['job_id' => $id], json_decode($published['message'], true));
    }

    public function test_full_lifecycle_over_rest(): void
    {
        $node = $this->node();
        $job = NodeJob::create(['id' => '01J0000000000000000000000A', 'client_id' => $node->id, 'type' => 'ping', 'params' => ['message' => 'x'], 'deadline_at' => now()->addMinute()]);

        $this->getJson('/api/nodes/job', $this->bearer($node))->assertOk()->assertJsonPath('job.id', $job->id)->assertJsonPath('job.params.message', 'x');
        $this->postJson("/api/nodes/jobs/{$job->id}/accept", [], $this->bearer($node))->assertOk()->assertJsonPath('job.state', 'accepted');
        $this->postJson("/api/nodes/jobs/{$job->id}/accept", [], $this->bearer($node))->assertOk(); // retry after a lost answer
        $this->postJson("/api/nodes/jobs/{$job->id}/progress", [], $this->bearer($node))->assertOk()->assertJsonPath('cancel_requested', false);
        $this->assertSame('running', $job->fresh()->state);

        $this->postJson("/api/nodes/jobs/{$job->id}/result", ['ok' => true, 'result' => ['pong' => 'x']], $this->bearer($node))
            ->assertOk()->assertJsonPath('job.state', 'succeeded')->assertJsonPath('job.result.pong', 'x');
        $this->postJson("/api/nodes/jobs/{$job->id}/result", ['ok' => false, 'error' => 'late'], $this->bearer($node))
            ->assertOk()->assertJsonPath('job.state', 'succeeded'); // idempotent, first result wins
        $this->getJson('/api/nodes/job', $this->bearer($node))->assertJsonPath('job', null);
    }

    public function test_a_second_node_cannot_touch_the_job_and_a_double_accept_conflicts(): void
    {
        $a = $this->node();
        $b = $this->node();
        $job = NodeJob::create(['id' => '01J0000000000000000000000B', 'client_id' => $a->id, 'type' => 'ping', 'deadline_at' => now()->addMinute(), 'state' => 'cancelled']);

        $this->postJson("/api/nodes/jobs/{$job->id}/accept", [], $this->bearer($b))->assertNotFound();
        $this->postJson("/api/nodes/jobs/{$job->id}/accept", [], $this->bearer($a))->assertStatus(409);
    }

    public function test_tokens_are_checked_and_a_plain_client_is_refused(): void
    {
        $this->getJson('/api/nodes/job')->assertUnauthorized();
        $this->getJson('/api/nodes/job', ['Authorization' => 'Bearer nope'])->assertUnauthorized();

        $client = Client::create(['status' => 'approved', 'registration_token' => Client::generateToken(), 'api_token' => Client::generateToken()]);
        $this->getJson('/api/nodes/job', $this->bearer($client))->assertUnauthorized();
        $this->getJson('/api/admin/me', $this->bearer($this->node()))->assertUnauthorized(); // a node token is no admin token
    }

    public function test_unknown_types_bad_params_and_missing_roles_are_refused(): void
    {
        $node = $this->node();
        $h = $this->adminHeaders();

        $this->postJson("/api/admin/nodes/{$node->id}/jobs", ['type' => 'exec', 'params' => ['cmd' => 'rm -rf /']], $h)->assertStatus(422);
        $this->postJson("/api/admin/nodes/{$node->id}/jobs", ['type' => 'ping', 'params' => ['message' => str_repeat('a', 101)]], $h)->assertStatus(422);
        $this->assertSame(0, NodeJob::count());
    }

    public function test_an_idempotency_key_returns_the_same_job(): void
    {
        $node = $this->node();
        $h = $this->adminHeaders();

        $first = $this->postJson("/api/admin/nodes/{$node->id}/jobs", ['type' => 'ping', 'idempotency_key' => 'k1'], $h)->assertCreated()->json('job.id');
        $this->postJson("/api/admin/nodes/{$node->id}/jobs", ['type' => 'ping', 'idempotency_key' => 'k1'], $h)->assertOk()->assertJsonPath('job.id', $first);
        $this->assertSame(1, NodeJob::count());
    }

    public function test_a_job_not_accepted_in_time_expires_and_a_silent_lease_is_lost(): void
    {
        $node = $this->node();
        $old = NodeJob::create(['id' => '01J0000000000000000000000C', 'client_id' => $node->id, 'type' => 'ping', 'deadline_at' => now()->subSecond()]);
        $stale = NodeJob::create(['id' => '01J0000000000000000000000D', 'client_id' => $node->id, 'type' => 'ping', 'state' => 'running', 'lease_until' => now()->subSecond()]);

        $this->getJson('/api/nodes/job', $this->bearer($node))->assertJsonPath('job', null);
        $this->assertSame('expired', $old->fresh()->state);
        $this->assertSame('lost', $stale->fresh()->state);
        $this->postJson("/api/nodes/jobs/{$old->id}/accept", [], $this->bearer($node))->assertStatus(409);
    }

    public function test_cancel_ends_a_queued_job_and_tells_a_running_one(): void
    {
        $node = $this->node();
        $h = $this->adminHeaders();
        $queued = NodeJob::create(['id' => '01J0000000000000000000000E', 'client_id' => $node->id, 'type' => 'ping', 'deadline_at' => now()->addMinute()]);
        $running = NodeJob::create(['id' => '01J0000000000000000000000F', 'client_id' => $node->id, 'type' => 'ping', 'state' => 'running', 'lease_until' => now()->addMinute()]);

        $this->postJson("/api/admin/nodes/{$node->id}/jobs/{$queued->id}/cancel", [], $h)->assertOk()->assertJsonPath('job.state', 'cancelled');
        $this->postJson("/api/admin/nodes/{$node->id}/jobs/{$running->id}/cancel", [], $h)->assertOk();
        $this->postJson("/api/nodes/jobs/{$running->id}/progress", [], $this->bearer($node))->assertJsonPath('cancel_requested', true);
        $this->postJson("/api/nodes/jobs/{$running->id}/result", ['ok' => false, 'error' => 'cancelled'], $this->bearer($node))->assertJsonPath('job.state', 'cancelled');
    }

    public function test_a_silent_node_with_a_role_raises_node_offline_and_resolves_when_it_returns(): void
    {
        $node = $this->node(['ripper'], ['ripper']);
        $node->update(['last_seen_at' => now()->subMinutes(5)]);
        $quiet = $this->node(); // no role switched on: never alerts
        $quiet->update(['last_seen_at' => now()->subDay()]);

        (new AlertChecks)->nodes();
        $this->assertSame(['node.offline'], Alerts::active()->pluck('kind')->all());

        $node->update(['last_seen_at' => now()]);
        (new AlertChecks)->nodes();
        $this->assertSame([], Alerts::active()->pluck('kind')->all());
    }
}
