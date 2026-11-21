<?php

namespace Tests\Feature;

use App\Jobs\ScrobbleToLastfm;
use App\Models\AdminToken;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Play;
use App\Models\SyncTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesSyncLibrary;
use Tests\TestCase;

/** Offline plays coming back from an app target (18.3 S5, server half): original time, idempotent, scrobbled when young enough. */
class SyncPlaysTest extends TestCase
{
    use MakesSyncLibrary, RefreshDatabase;

    private string $token;

    private string $target;

    private int $track;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeLibrary();
        User::factory()->create();
        [, $this->token] = AdminToken::issue('test');
        $this->target = $this->postJson('/api/admin/sync/targets', ['name' => 'Phone', 'kind' => 'app', 'client_ref' => 'i1'], $this->auth())->assertCreated()->json('id');
        $this->track = $this->track($this->album(), 'Song')->id;
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->removeLibrary();
        parent::tearDown();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function play(string $id, string $at, int $seconds = 180, ?int $track = null): array
    {
        return ['client_id' => $id, 'track_id' => $track ?? $this->track, 'started_at' => $at, 'played_seconds' => $seconds];
    }

    public function test_plays_are_stored_with_their_original_time_and_scrobbled(): void
    {
        $at = now()->subDays(2)->startOfSecond();
        $this->postJson("/api/admin/sync/targets/{$this->target}/plays", ['plays' => [$this->play('a', $at->toIso8601String())]], $this->auth())
            ->assertOk()->assertJsonPath('accepted', 1)->assertJsonPath('duplicates', 0)->assertJsonPath('scrobbled', 1);

        $play = Play::first();
        $this->assertSame('offline', $play->source_type);
        $this->assertSame($this->track, $play->track_id);
        $this->assertSame($at->timestamp, $play->played_at->timestamp);
        $this->assertSame($at->timestamp + 180, $play->ended_at->timestamp);
        $this->assertFalse($play->skipped);
        Queue::assertPushed(ScrobbleToLastfm::class, 1);
    }

    public function test_resending_the_same_plays_is_idempotent(): void
    {
        $body = ['plays' => [$this->play('a', now()->subDay()->toIso8601String())]];
        $this->postJson("/api/admin/sync/targets/{$this->target}/plays", $body, $this->auth())->assertJsonPath('accepted', 1);
        $this->postJson("/api/admin/sync/targets/{$this->target}/plays", $body, $this->auth())->assertJsonPath('accepted', 0)->assertJsonPath('duplicates', 1);
        // the report carries them too, same dedupe
        $this->postJson("/api/admin/sync/targets/{$this->target}/report", $body + ['applied' => []], $this->auth())->assertOk()->assertJsonPath('plays.duplicates', 1);

        $this->assertSame(1, Play::count());
        $this->assertSame(1, Device::where('hidden', true)->count());
        $this->assertSame($this->target, DeviceMeta::where('key', 'sync_target')->value('value'));
        Queue::assertPushed(ScrobbleToLastfm::class, 1);
    }

    public function test_old_short_future_and_unknown_plays(): void
    {
        $r = $this->postJson("/api/admin/sync/targets/{$this->target}/plays", ['plays' => [
            $this->play('old', now()->subDays(20)->toIso8601String()),
            $this->play('short', now()->subHour()->toIso8601String(), 10),
            $this->play('future', now()->addDay()->toIso8601String()),
            $this->play('ghost', now()->subHour()->toIso8601String(), 100, 999999),
            $this->play('garbage', 'not a date'),
        ]], $this->auth())->assertOk();

        $r->assertJsonPath('accepted', 2)->assertJsonPath('too_old', 1)->assertJsonPath('scrobbled', 0);
        $this->assertEqualsCanonicalizing(['future' => 'bad_started_at', 'ghost' => 'unknown_track', 'garbage' => 'bad_started_at'], collect($r->json('rejected'))->pluck('error', 'client_id')->all());
        $this->assertTrue(Play::where('played_at', '<', now()->subDays(15))->exists(), 'an old play still reaches the history');
        $this->assertTrue((bool) Play::where('source_type', 'offline')->orderBy('played_at', 'desc')->first()->skipped);
        Queue::assertNothingPushed();
    }

    public function test_validation_and_auth_and_node_targets(): void
    {
        $this->postJson("/api/admin/sync/targets/{$this->target}/plays", ['plays' => [['client_id' => 'x']]], $this->auth())->assertStatus(422);
        $this->postJson("/api/admin/sync/targets/{$this->target}/plays", ['plays' => array_fill(0, 501, $this->play('a', now()->toIso8601String()))], $this->auth())->assertStatus(422);
        $this->postJson("/api/admin/sync/targets/{$this->target}/plays", ['plays' => []])->assertUnauthorized();

        $node = SyncTarget::create(['name' => 'Stick', 'kind' => SyncTarget::NODE, 'node_id' => Client::create(['name' => 'Pi', 'type' => 'single', 'kind' => 'node', 'status' => 'approved', 'roles' => ['sync-target'], 'enabled_roles' => ['sync-target'], 'api_token' => 'nt', 'hardware_id' => 'h1', 'registration_token' => 'r1'])->id, 'rules' => ['selections' => []]]);
        $this->postJson("/api/admin/sync/targets/{$node->id}/plays", ['plays' => [$this->play('a', now()->subHour()->toIso8601String())]], $this->auth())
            ->assertStatus(422)->assertJsonPath('error', 'not_an_app_target');
        $this->assertSame(0, Play::count());
    }
}
