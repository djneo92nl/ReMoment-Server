<?php

namespace Tests\Feature\Api;

use App\Domain\Health\QueuePause;
use App\Jobs\ProcessArtwork;
use App\Jobs\QueueHeartbeat;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools\Queues as QueuesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** `/api/admin/queues` and the MCP tool on top of it (docs/api/admin-queues.md). */
class AdminQueuesApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsAdmin;

    private function row(string $queue, string $class = ProcessArtwork::class, array $extra = []): array
    {
        return array_merge([
            'queue' => $queue,
            'payload' => json_encode(['uuid' => (string) Str::uuid(), 'displayName' => $class, 'data' => ['commandName' => $class, 'command' => '']]),
            'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ], $extra);
    }

    public function test_every_route_needs_the_admin_token(): void
    {
        $this->assertNeedsAdminToken([
            ['GET', '/api/admin/queues'],
            ['POST', '/api/admin/queues/default/pause'],
            ['POST', '/api/admin/queues/default/resume'],
            ['POST', '/api/admin/queues/default/cancel'],
            ['POST', '/api/admin/queues/default/prioritise'],
        ]);
    }

    public function test_the_list_has_one_row_per_queue_with_the_health_fields(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([$this->row('default'), $this->row('default', extra: ['reserved_at' => now()->timestamp])]);

        $response = $this->getJson('/api/admin/queues', $this->adminHeaders())->assertOk()
            ->assertJsonPath('driver', 'database')->assertJsonPath('supported', true)->assertJsonPath('can_reorder', false);

        $default = collect($response->json('data'))->firstWhere('queue', 'default');
        $this->assertSame(1, $default['waiting']);
        $this->assertSame(1, $default['running']);
        $this->assertEqualsCanonicalizing(
            ['queue', 'state', 'paused', 'pausable', 'waiting', 'delayed', 'running', 'oldest_waiting_at', 'oldest_age_seconds', 'workers', 'worker', 'failed_24h', 'last_progress_at', 'heartbeat_at', 'lag_after_seconds', 'lagging'],
            array_keys($default),
        );
        $this->assertFalse(collect($response->json('data'))->firstWhere('queue', 'health')['pausable']);
    }

    public function test_the_sync_driver_lists_nothing_and_cannot_be_managed(): void
    {
        $this->getJson('/api/admin/queues', $this->adminHeaders())->assertOk()
            ->assertJsonPath('supported', false)->assertJsonPath('data', []);

        $this->postJson('/api/admin/queues/default/cancel', [], $this->adminHeaders())->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    public function test_pause_and_resume_answer_the_queue_row(): void
    {
        config(['queue.default' => 'database']);
        $headers = $this->adminHeaders();

        $this->postJson('/api/admin/queues/heavy/pause', [], $headers)->assertOk()
            ->assertJsonPath('data.queue', 'heavy')->assertJsonPath('data.paused', true)->assertJsonPath('data.state', 'paused');
        $this->assertTrue(QueuePause::isPaused('heavy'));

        $this->postJson('/api/admin/queues/heavy/resume', [], $headers)->assertOk()->assertJsonPath('data.paused', false);
        $this->assertFalse(QueuePause::isPaused('heavy'));
    }

    public function test_health_cannot_be_paused_and_unknown_queues_are_404(): void
    {
        $headers = $this->adminHeaders();

        $this->postJson('/api/admin/queues/health/pause', [], $headers)->assertStatus(422)->assertJsonPath('error', 'invalid');
        $this->postJson('/api/admin/queues/nonsense/pause', [], $headers)->assertNotFound();
        $this->postJson('/api/admin/queues/nonsense/resume', [], $headers)->assertNotFound();
        $this->assertSame([], QueuePause::all());
    }

    public function test_cancel_returns_the_count_and_can_be_limited_to_a_class(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([
            $this->row('default'), $this->row('default'), $this->row('default', 'App\\Jobs\\ScrobbleToLastfm'),
            $this->row('default', QueueHeartbeat::class), $this->row('default', extra: ['reserved_at' => now()->timestamp]),
        ]);
        $headers = $this->adminHeaders();

        $this->postJson('/api/admin/queues/default/cancel', ['job' => 'ProcessArtwork'], $headers)->assertOk()
            ->assertExactJson(['cancelled' => 2, 'skipped_running' => 0]);

        $this->postJson('/api/admin/queues/default/cancel', [], $headers)->assertOk()->assertJsonPath('cancelled', 1);
        $this->assertSame(2, DB::table('jobs')->count());   // the running job and the heartbeat
    }

    public function test_prioritise_needs_a_job_and_a_driver_that_can_reorder(): void
    {
        config(['queue.default' => 'database']);
        $headers = $this->adminHeaders();

        $this->postJson('/api/admin/queues/default/prioritise', [], $headers)->assertStatus(422)->assertJsonValidationErrors('job');
        $this->postJson('/api/admin/queues/default/prioritise', ['job' => 'ProcessArtwork'], $headers)
            ->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    public function test_the_mcp_tool_is_admin_only_and_goes_through_the_api(): void
    {
        config(['queue.default' => 'database']);
        ReMomentServer::tool(QueuesTool::class, [])->assertNotRegistered();

        DB::table('jobs')->insert([$this->row('default'), $this->row('default')]);
        request()->headers->set('Authorization', $this->adminHeaders()['Authorization']);

        ReMomentServer::tool(QueuesTool::class, [])->assertOk()->assertSee('"queue":"default"');
        ReMomentServer::tool(QueuesTool::class, ['action' => 'pause', 'queue' => 'analysis'])->assertOk()->assertSee('"paused":true');
        $this->assertTrue(QueuePause::isPaused('analysis'));
        ReMomentServer::tool(QueuesTool::class, ['action' => 'resume', 'queue' => 'analysis'])->assertOk()->assertSee('"paused":false');
        ReMomentServer::tool(QueuesTool::class, ['action' => 'cancel', 'queue' => 'default', 'job' => 'ProcessArtwork'])->assertOk()->assertSee('"cancelled":2');
        ReMomentServer::tool(QueuesTool::class, ['action' => 'pause', 'queue' => 'health'])->assertHasErrors();
        ReMomentServer::tool(QueuesTool::class, ['action' => 'prioritise', 'queue' => 'default'])->assertHasErrors();
    }
}
