<?php

namespace Tests\Feature;

use App\Domain\Sync\SyncArtifacts;
use App\Jobs\PrepareSyncArtifact;
use App\Models\AdminToken;
use App\Models\SyncArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MakesSyncLibrary;
use Tests\TestCase;

/** Sync artifacts (S1/S2): transcodes made once per fingerprint through the export machinery, a capped LRU cache, preparing state. Real ffmpeg on one-second tones. */
class SyncArtifactsTest extends TestCase
{
    use MakesSyncLibrary, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeLibrary();
        config(['export.nice' => 0]);
        User::factory()->create();
        [, $this->token] = AdminToken::issue('test');
    }

    protected function tearDown(): void
    {
        $this->removeLibrary();
        parent::tearDown();
    }

    private function needFfmpeg(): void
    {
        $r = Process::run([config('analysis.ffmpeg'), '-hide_banner', '-encoders']);
        if (!$r->successful() || !str_contains($r->output(), 'libmp3lame')) {
            $this->markTestSkipped('ffmpeg with libmp3lame is not available.');
        }
    }

    private function target(array $rules): string
    {
        return $this->postJson('/api/admin/sync/targets', ['name' => 'Phone', 'kind' => 'app', 'client_ref' => Str::random(10), 'rules' => $rules], ['Authorization' => 'Bearer '.$this->token])->assertCreated()->json('id');
    }

    private function plan(string $id, array $have = [])
    {
        return $this->postJson("/api/admin/sync/targets/{$id}/plan", ['have' => $have], ['Authorization' => 'Bearer '.$this->token]);
    }

    public function test_a_transcode_is_prepared_in_the_background_then_served_ranged(): void
    {
        $this->needFfmpeg();
        Queue::fake();
        $album = $this->album();
        $this->track($album, 'Lossless', 'flac', 900, real: true);
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]], 'profile' => 'mp3-128']);

        $first = $this->plan($id)->assertOk();
        $first->assertJsonPath('add.0.state', 'preparing')->assertJsonPath('add.0.url', null)->assertJsonPath('totals.preparing', 1)->assertJsonPath('totals.transcoded', 1);
        $this->assertStringEndsWith('.mp3', $first->json('add.0.path'));
        Queue::assertPushed(PrepareSyncArtifact::class, fn ($job) => $job->queue === 'heavy' && $job->fingerprint === $first->json('add.0.fingerprint'));

        // The download of an artifact that is not ready is "wait", never a failure.
        $fingerprint = $first->json('add.0.fingerprint');
        $this->get("/api/admin/sync/files/{$id}/{$first->json('add.0.key')}/{$fingerprint}", ['Authorization' => 'Bearer '.$this->token])->assertStatus(409)->assertJsonPath('error', 'preparing');

        // The worker makes it.
        (new PrepareSyncArtifact($fingerprint, Queue::pushedJobs()[PrepareSyncArtifact::class][0]['job']->spec))->handle();
        $artifact = SyncArtifact::where('fingerprint', $fingerprint)->first();
        $this->assertSame('ready', $artifact->state);
        $this->assertGreaterThan(0, $artifact->size);
        Storage::disk('local')->assertExists("sync-artifacts/{$fingerprint}.mp3");

        $second = $this->plan($id)->assertOk();
        $second->assertJsonPath('add.0.state', 'ready')->assertJsonPath('add.0.sha256', $artifact->sha256)->assertJsonPath('add.0.size', $artifact->size)->assertJsonPath('totals.preparing', 0);
        $this->assertSame($artifact->sha256, hash_file('sha256', Storage::disk('local')->path("sync-artifacts/{$fingerprint}.mp3")));

        $url = $second->json('add.0.url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'audio/mpeg')->assertHeader('X-Sync-Sha256', $artifact->sha256);
        $this->get($url, ['Range' => 'bytes=0-99'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-99/'.$artifact->size);

        // Applied: the next plan is empty. A profile change replaces the file (new fingerprint).
        $have = $this->applied($second->json());
        $this->plan($id, $have)->assertOk()->assertJsonCount(0, 'add')->assertJsonCount(0, 'replace');
        $this->putJson("/api/admin/sync/targets/{$id}", ['rules' => ['selections' => [['type' => 'album', 'id' => $album->id]], 'profile' => 'mp3-192']], ['Authorization' => 'Bearer '.$this->token]);
        $this->plan($id, $have)->assertOk()->assertJsonCount(1, 'replace')->assertJsonPath('replace.0.state', 'preparing');
    }

    public function test_two_targets_with_the_same_effective_encode_share_one_artifact(): void
    {
        $this->needFfmpeg();
        Queue::fake();
        $album = $this->album();
        $this->track($album, 'Lossless', 'flac', 900, real: true);
        $a = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]], 'profile' => 'mp3-128']);
        $b = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]], 'profile' => 'mp3-128', 'budget' => ['bytes' => 10 ** 9]]);

        $fa = $this->plan($a)->json('add.0.fingerprint');
        $fb = $this->plan($b)->json('add.0.fingerprint');
        $this->assertSame($fa, $fb);
        $this->assertSame(1, SyncArtifact::count());
        Queue::assertPushed(PrepareSyncArtifact::class, 1);
    }

    public function test_prepare_ahead_is_capped_per_plan(): void
    {
        Queue::fake();
        config(['sync.prepare_ahead' => 2]);
        $album = $this->album();
        foreach (range(1, 4) as $n) {
            $this->track($album, "t{$n}", 'flac', 900, size: 3000, number: $n);
        }
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]], 'profile' => 'mp3-128']);

        $plan = $this->plan($id)->assertOk();
        $plan->assertJsonCount(4, 'add')->assertJsonPath('totals.preparing', 4);
        Queue::assertPushed(PrepareSyncArtifact::class, 2);
        $this->assertSame(2, SyncArtifact::count());
        // Asking again while those are still being made starts no more.
        $this->plan($id)->assertOk();
        Queue::assertPushed(PrepareSyncArtifact::class, 2);
    }

    public function test_a_failed_artifact_is_reported_and_retried_later(): void
    {
        Queue::fake();
        $album = $this->album();
        $this->track($album, 'Broken', 'flac', 900, size: 3000); // random bytes: ffmpeg cannot read them
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]], 'profile' => 'mp3-128']);
        $fp = $this->plan($id)->json('add.0.fingerprint');
        $this->needFfmpeg();
        (new PrepareSyncArtifact($fp, Queue::pushedJobs()[PrepareSyncArtifact::class][0]['job']->spec))->handle();

        $this->assertSame('failed', SyncArtifact::first()->state);
        $this->plan($id)->assertOk()->assertJsonPath('add.0.state', 'failed')->assertJsonPath('totals.failed', 1);
        Queue::assertPushed(PrepareSyncArtifact::class, 1); // not at once again

        $this->travel(11)->minutes();
        \Illuminate\Support\Facades\Cache::lock('laravel_unique_job:'.PrepareSyncArtifact::class.':'.$fp)->forceRelease(); // what a finished worker job releases
        $this->plan($id)->assertOk()->assertJsonPath('add.0.state', 'preparing');
        Queue::assertPushed(PrepareSyncArtifact::class, 2);
    }

    public function test_with_the_cache_off_transcodes_are_left_out_with_a_warning_and_copies_still_sync(): void
    {
        config(['sync.cache_gb' => 0]);
        $album = $this->album();
        $this->track($album, 'Copy', 'mp3', 128, number: 1);
        $this->track($album, 'Lossless', 'flac', 900, number: 2);
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]], 'profile' => 'mp3-192']);

        $this->plan($id)->assertOk()->assertJsonCount(1, 'add')->assertJsonPath('warnings.0.code', 'artifact_cache_off');
    }

    public function test_the_cache_is_pruned_least_recently_used_first_and_never_what_a_plan_just_used(): void
    {
        config(['sync.cache_gb' => 3000 / 1024 ** 3, 'sync.protect_minutes' => 60]); // 3000 bytes
        $disk = SyncArtifacts::disk();
        $make = function (string $fp, int $size, $usedAt) use ($disk) {
            $disk->put("sync-artifacts/{$fp}.mp3", str_repeat('x', $size));

            return SyncArtifact::create(['fingerprint' => $fp, 'state' => 'ready', 'extension' => 'mp3', 'size' => $size, 'last_used_at' => $usedAt]);
        };
        $make(str_repeat('a', 40), 1500, now()->subDays(3));
        $make(str_repeat('b', 40), 1500, now()->subDays(2));
        $make(str_repeat('c', 40), 1500, now()->subDays(1));
        $make(str_repeat('d', 40), 1500, now()->subMinutes(5)); // used by a plan a moment ago: protected

        $r = SyncArtifacts::prune();
        $this->assertSame(2, $r['removed']);
        $this->assertSame(['c', 'd'], SyncArtifact::orderBy('fingerprint')->pluck('fingerprint')->map(fn ($f) => $f[0])->all());
        $disk->assertMissing('sync-artifacts/'.str_repeat('a', 40).'.mp3');
        $disk->assertExists('sync-artifacts/'.str_repeat('d', 40).'.mp3');

        // Over the cap, but everything left is protected: nothing more goes.
        SyncArtifact::query()->update(['last_used_at' => now()]);
        $this->assertSame(0, SyncArtifacts::prune()['removed']);
    }

    public function test_prune_drops_stuck_rows_and_orphan_files(): void
    {
        $disk = SyncArtifacts::disk();
        $stuck = SyncArtifact::create(['fingerprint' => str_repeat('e', 40), 'state' => 'preparing', 'extension' => 'mp3']);
        SyncArtifact::whereKey($stuck->id)->update(['updated_at' => now()->subDay()]);
        $disk->put('sync-artifacts/'.str_repeat('f', 40).'.mp3', 'orphan');
        touch($disk->path('sync-artifacts/'.str_repeat('f', 40).'.mp3'), time() - 7200);
        $disk->put('sync-artifacts/'.str_repeat('9', 40).'.mp3', 'fresh orphan'); // an hour not yet: a job may be writing it

        $r = SyncArtifacts::prune();
        $this->assertSame(1, $r['stale']);
        $this->assertSame(1, $r['orphans']);
        $this->assertSame(0, SyncArtifact::count());
        $disk->assertExists('sync-artifacts/'.str_repeat('9', 40).'.mp3');
    }

    public function test_loudness_gain_changes_the_fingerprint_of_a_transcode_only_and_never_a_copy(): void
    {
        Queue::fake();
        $album = $this->album();
        $copy = $this->track($album, 'Copy', 'mp3', 128, number: 1);
        $lossless = $this->track($album, 'Lossless', 'flac', 900, number: 2);
        foreach ([$copy, $lossless] as $t) {
            \App\Models\FileAnalysis::create(['file_id' => $t->trackFiles()->first()->file_id, 'loudness_lufs' => -24.0, 'true_peak' => -12.0, 'status' => 'done']);
        }
        $fp = fn (string $loudness) => collect($this->plan($this->target(['selections' => [['type' => 'album', 'id' => $album->id]], 'profile' => 'mp3-192', 'options' => ['loudness' => $loudness]]))->assertOk()->json('add'))->pluck('fingerprint', 'key')->all();

        $off = $fp('off');
        $track = $fp('track');
        $this->assertSame($off["track:{$copy->id}"], $track["track:{$copy->id}"]); // a copy stays bit-exact
        $this->assertNotSame($off["track:{$lossless->id}"], $track["track:{$lossless->id}"]); // a +6 dB gain is baked into the transcode
    }
}
