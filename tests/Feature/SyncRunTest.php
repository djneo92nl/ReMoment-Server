<?php

namespace Tests\Feature;

use App\Domain\Sync\Syncs;
use App\Domain\Tasks\UserTasks;
use App\Models\AdminToken;
use App\Models\Alert;
use App\Models\Client;
use App\Models\NodeJob;
use App\Models\SyncTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Running a sync on a node (S2 server side): node jobs, progress in the task tray, the stored result, alerts, one job per target. */
class SyncRunTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Client $node;

    private SyncTarget $target;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        [, $this->token] = AdminToken::issue('test');
        $this->node = Client::create(['name' => 'Pi', 'type' => 'single', 'kind' => 'node', 'status' => 'approved', 'roles' => ['sync-target'], 'enabled_roles' => ['sync-target'],
            'api_token' => 'node-token', 'hardware_id' => 'h1', 'registration_token' => 'r1']);
        $this->target = SyncTarget::create(['name' => 'Car stick', 'kind' => 'node', 'node_id' => $this->node->id, 'rules' => ['selections' => []]]);
    }

    private function admin(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function node(): array
    {
        return ['Authorization' => 'Bearer node-token'];
    }

    public function test_sync_now_queues_apply_for_the_node_and_the_node_gets_the_server_id(): void
    {
        $id = $this->postJson("/api/admin/sync/targets/{$this->target->id}/run", ['confirm' => true], $this->admin())->assertStatus(202)->json('job_id');

        $job = NodeJob::find($id);
        $this->assertSame('sync.apply', $job->type);
        $this->assertSame('sync-target', $job->role);
        $this->assertSame(['target_id' => $this->target->id, 'confirm' => true], $job->params);
        $this->assertSame($id, $this->target->fresh()->active_job_id);

        $delivered = $this->getJson('/api/nodes/job', $this->node())->assertOk()->json('job');
        $this->assertSame($id, $delivered['id']);
        $this->assertSame(Syncs::serverId(), $delivered['params']['server_id']);
        $this->assertSame($delivered['params']['server_id'], Syncs::serverId()); // stable
        // The admin listing of the job never needs the id, and the stored params do not hold it.
        $this->assertArrayNotHasKey('server_id', NodeJob::find($id)->params);

        $this->assertSame('Sync to Car stick', collect(UserTasks::active())->firstWhere('key', "sync:{$this->target->id}")['title']);
    }

    public function test_one_job_per_target_and_the_role_must_be_on(): void
    {
        $this->postJson("/api/admin/sync/targets/{$this->target->id}/run", [], $this->admin())->assertStatus(202);
        $this->postJson("/api/admin/sync/targets/{$this->target->id}/run", [], $this->admin())->assertStatus(409)->assertJsonPath('error', 'target_busy');
        $this->postJson("/api/admin/sync/targets/{$this->target->id}/volume", ['action' => 'scan'], $this->admin())->assertStatus(409);
        $this->deleteJson("/api/admin/sync/targets/{$this->target->id}", [], $this->admin())->assertStatus(409);

        $this->node->update(['enabled_roles' => []]);
        $this->target->update(['active_job_id' => null]);
        $this->postJson("/api/admin/sync/targets/{$this->target->id}/run", [], $this->admin())->assertStatus(422)->assertJsonPath('error', 'node_unavailable');

        $app = SyncTarget::create(['name' => 'Phone', 'kind' => 'app', 'client_ref' => 'x']);
        $this->postJson("/api/admin/sync/targets/{$app->id}/run", [], $this->admin())->assertStatus(422)->assertJsonPath('error', 'not_a_node_target');
        $this->postJson("/api/admin/sync/targets/{$this->target->id}/run", [], [])->assertStatus(401);
    }

    public function test_progress_reaches_the_task_tray_and_the_result_is_stored(): void
    {
        $id = $this->postJson("/api/admin/sync/targets/{$this->target->id}/run", [], $this->admin())->json('job_id');
        $this->postJson("/api/nodes/jobs/{$id}/accept", [], $this->node())->assertOk();
        $this->postJson("/api/nodes/jobs/{$id}/progress", ['progress' => ['phase' => 'download', 'files_done' => 25, 'files_total' => 100, 'bytes_done' => 5, 'bytes_total' => 20, 'current' => 'a.mp3']], $this->node())
            ->assertOk()->assertJsonPath('cancel_requested', false);

        $task = collect(UserTasks::active())->firstWhere('key', "sync:{$this->target->id}");
        $this->assertSame('running', $task['state']);
        $this->assertSame(25, $task['percent']);
        $this->assertSame('25 of 100', $task['detail']);

        $this->postJson("/api/nodes/jobs/{$id}/result", ['ok' => true, 'result' => [
            'state' => 'done', 'applied' => 98, 'failed' => 2, 'released' => 1, 'removed' => 3, 'moved' => 0, 'bytes_written' => 123, 'owned_bytes' => 5000, 'owned_count' => 98,
        ]], $this->node())->assertOk();

        $t = $this->target->fresh();
        $this->assertNull($t->active_job_id);
        $this->assertSame('succeeded', $t->last_run['state']);
        $this->assertSame('done', $t->last_run['result_state']);
        $this->assertSame(98, $t->last_run['summary']['applied']);
        $this->assertSame(2, $t->last_run['summary']['failed']);
        $this->assertSame(5000, $t->owned_bytes);
        $this->assertNull(collect(UserTasks::active())->firstWhere('key', "sync:{$this->target->id}"));
        $this->assertSame(0, Alert::whereNull('resolved_at')->where('kind', 'sync.failed')->count());

        $this->getJson("/api/admin/sync/targets/{$this->target->id}", $this->admin())->assertOk()->assertJsonPath('running', false)->assertJsonPath('last_run.summary.applied', 98);
    }

    public function test_a_failed_run_raises_an_alert_that_the_next_success_resolves(): void
    {
        $fail = $this->postJson("/api/admin/sync/targets/{$this->target->id}/run", [], $this->admin())->json('job_id');
        $this->postJson("/api/nodes/jobs/{$fail}/accept", [], $this->node());
        $this->postJson("/api/nodes/jobs/{$fail}/result", ['ok' => false, 'error' => 'volume_not_found'], $this->node())->assertOk();

        $alert = Alert::whereNull('resolved_at')->where('kind', 'sync.failed')->first();
        $this->assertNotNull($alert);
        $this->assertSame($this->target->id, $alert->key);
        $this->assertStringContainsString('volume_not_found', $alert->message);
        $this->assertSame('failed', $this->target->fresh()->last_run['state']);

        $ok = $this->postJson("/api/admin/sync/targets/{$this->target->id}/run", [], $this->admin())->json('job_id');
        $this->postJson("/api/nodes/jobs/{$ok}/accept", [], $this->node());
        $this->postJson("/api/nodes/jobs/{$ok}/result", ['ok' => true, 'result' => ['state' => 'done']], $this->node());
        $this->assertSame(0, Alert::whereNull('resolved_at')->where('kind', 'sync.failed')->count());
    }

    public function test_a_lost_job_is_reconciled_and_cancel_ends_a_queued_one(): void
    {
        $id = $this->postJson("/api/admin/sync/targets/{$this->target->id}/run", [], $this->admin())->json('job_id');
        $this->postJson("/api/nodes/jobs/{$id}/accept", [], $this->node());
        NodeJob::whereKey($id)->update(['lease_until' => now()->subMinute()]); // the node vanished

        Syncs::reconcile();
        $t = $this->target->fresh();
        $this->assertNull($t->active_job_id);
        $this->assertSame('lost', $t->last_run['state']);
        $this->assertNotNull(Alert::whereNull('resolved_at')->where('kind', 'sync.failed')->first());
        $this->assertNull(collect(UserTasks::active())->firstWhere('key', "sync:{$this->target->id}"));

        $queued = $this->postJson("/api/admin/sync/targets/{$this->target->id}/run", [], $this->admin())->json('job_id');
        $this->deleteJson("/api/admin/sync/targets/{$this->target->id}/run", [], $this->admin())->assertOk()->assertJsonPath('cancelled', true);
        $this->assertSame('cancelled', NodeJob::find($queued)->state);
        $this->assertNull($this->target->fresh()->active_job_id);
        $this->assertSame(0, Alert::whereNull('resolved_at')->where('kind', 'sync.failed')->where('data->job_id', $queued)->count());
    }

    public function test_a_running_job_is_told_to_cancel_on_its_next_progress_call(): void
    {
        $id = $this->postJson("/api/admin/sync/targets/{$this->target->id}/run", [], $this->admin())->json('job_id');
        $this->postJson("/api/nodes/jobs/{$id}/accept", [], $this->node());
        $this->deleteJson("/api/admin/sync/targets/{$this->target->id}/run", [], $this->admin())->assertOk();
        $this->postJson("/api/nodes/jobs/{$id}/progress", [], $this->node())->assertOk()->assertJsonPath('cancel_requested', true);
    }

    public function test_scan_and_claim_jobs_name_the_target_only(): void
    {
        $scan = $this->postJson("/api/admin/sync/targets/{$this->target->id}/volume", ['action' => 'scan'], $this->admin())->assertStatus(202)->json('job_id');
        $this->assertSame(['target_id' => $this->target->id], NodeJob::find($scan)->params);
        $this->assertSame($scan, Syncs::lastJob($this->target, 'sync.scan')->id);

        $this->postJson("/api/nodes/jobs/{$scan}/accept", [], $this->node());
        $this->postJson("/api/nodes/jobs/{$scan}/result", ['ok' => true, 'result' => ['marker' => null, 'free' => 10]], $this->node());
        $this->assertNull($this->target->fresh()->active_job_id);

        $this->postJson("/api/admin/sync/targets/{$this->target->id}/volume", ['action' => 'claim'], $this->admin())->assertStatus(202);
        $this->postJson("/api/admin/sync/targets/{$this->target->id}/volume", ['action' => 'format'], $this->admin())->assertStatus(422);
        $this->assertSame(1, NodeJob::where('type', 'sync.claim')->count());
    }
}
