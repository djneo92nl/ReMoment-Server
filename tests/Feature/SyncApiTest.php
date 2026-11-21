<?php

namespace Tests\Feature;

use App\Models\AdminToken;
use App\Models\Client;
use App\Models\Media\Playlist;
use App\Models\Play;
use App\Models\SyncTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MakesSyncLibrary;
use Tests\TestCase;

/** Sync server core (workstream 18.3 S1): rules, the stateless plan for each selection type and fill mode, signed ranged downloads. */
class SyncApiTest extends TestCase
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

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function target(array $rules, string $name = 'Phone'): string
    {
        return $this->postJson('/api/admin/sync/targets', ['name' => $name, 'kind' => 'app', 'client_ref' => Str::random(12), 'rules' => $rules], $this->auth())
            ->assertCreated()->json('id');
    }

    private function plan(string $id, array $have = [], array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/admin/sync/targets/{$id}/plan", ['have' => $have] + $extra, $this->auth());
    }

    public function test_rules_are_validated_and_defaults_filled(): void
    {
        $r = $this->postJson('/api/admin/sync/targets', ['name' => 'P', 'kind' => 'app', 'client_ref' => 'abc', 'rules' => ['selections' => [['type' => 'favorites']]]], $this->auth())
            ->assertCreated();
        $r->assertJsonPath('rules.profile', 'mp3-320')->assertJsonPath('rules.fill', 'all')->assertJsonPath('rules.options.layout', 'tree')
            ->assertJsonPath('rules.budget.reserve_percent', 5)->assertJsonPath('rules_version', 1);

        $bad = fn (array $rules) => $this->postJson('/api/admin/sync/targets', ['name' => 'P', 'kind' => 'app', 'client_ref' => Str::random(8), 'rules' => $rules], $this->auth());
        $bad(['selections' => [['type' => 'genre']]])->assertStatus(422)->assertJsonValidationErrors(['rules.selections.0.type']);
        $bad(['selections' => [['type' => 'album']]])->assertStatus(422);
        $bad(['selections' => [['type' => 'favorites']], 'profile' => 'wav'])->assertStatus(422);
        $bad(['selections' => [['type' => 'favorites']], 'options' => ['layout' => 'flat']])->assertStatus(422);
        $bad(['selections' => [['type' => 'favorites']], 'options' => ['folder_cover' => true]])->assertStatus(422);
        $bad(['selections' => [['type' => 'recently_added']]])->assertStatus(422);
    }

    public function test_app_target_is_idempotent_on_client_ref_and_a_rules_edit_bumps_the_version(): void
    {
        $body = ['name' => 'Phone', 'kind' => 'app', 'client_ref' => 'install-1'];
        $id = $this->postJson('/api/admin/sync/targets', $body, $this->auth())->assertCreated()->json('id');
        $this->postJson('/api/admin/sync/targets', $body, $this->auth())->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(1, SyncTarget::count());

        $this->putJson("/api/admin/sync/targets/{$id}", ['rules' => ['selections' => [['type' => 'favorites']]]], $this->auth())->assertOk()->assertJsonPath('rules_version', 2);
        $this->putJson("/api/admin/sync/targets/{$id}", ['rules' => ['selections' => [['type' => 'favorites']]]], $this->auth())->assertOk()->assertJsonPath('rules_version', 2);
        $this->putJson("/api/admin/sync/targets/{$id}", ['name' => 'Renamed'], $this->auth())->assertOk()->assertJsonPath('name', 'Renamed')->assertJsonPath('rules_version', 2);

        $this->getJson('/api/admin/sync/targets')->assertStatus(401);
        $this->getJson('/api/admin/sync/targets', $this->auth())->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/admin/sync/targets/{$id}", [], $this->auth())->assertOk();
        $this->assertSame(0, SyncTarget::count());
    }

    public function test_album_plan_then_applied_manifest_gives_an_empty_plan(): void
    {
        $album = $this->album();
        $this->track($album, 'One', 'mp3', 128, 4000, 1);
        $this->track($album, 'Two', 'mp3', 128, 5000, 2);
        $this->track($album, 'Streamed', null, 0, 0, 3);
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]]]);

        $first = $this->plan($id)->assertOk();
        $first->assertJsonCount(2, 'add')->assertJsonPath('totals.bytes_to_add', 9000)->assertJsonPath('totals.copied', 2)
            ->assertJsonPath('warnings.0.code', 'no_own_file')->assertJsonPath('warnings.0.count', 1);
        $paths = array_column($first->json('add'), 'path');
        $this->assertSame(['Band/LP/01 - One.mp3', 'Band/LP/02 - Two.mp3'], $paths);
        $this->assertSame('ready', $first->json('add.0.state'));
        $this->assertStringStartsWith('/api/sync/files/', $first->json('add.0.url'));

        $have = $this->applied($first->json());
        $second = $this->plan($id, $have)->assertOk();
        $second->assertJsonCount(0, 'add')->assertJsonCount(0, 'replace')->assertJsonCount(0, 'move')->assertJsonCount(0, 'remove')->assertJsonPath('keep', 2);
    }

    public function test_each_selection_type_and_priority_between_selections(): void
    {
        $a = $this->album('A', 'Alpha');
        $b = $this->album('B', 'Beta');
        $t1 = $this->track($a, 'a1', number: 1);
        $t2 = $this->track($a, 'a2', number: 2);
        $t3 = $this->track($b, 'b1', number: 1);
        $t4 = $this->track($b, 'b2', number: 2);

        $keys = function (array $rules) {
            $id = $this->target($rules);
            $keys = array_column($this->plan($id)->assertOk()->json('add'), 'key');
            $this->deleteJson("/api/admin/sync/targets/{$id}", [], $this->auth());

            return $keys;
        };

        $playlist = Playlist::create(['name' => 'Mix']);
        $playlist->tracks()->attach([$t4->id => ['position' => 1], $t1->id => ['position' => 2]]);
        $this->assertSame(["track:{$t4->id}", "track:{$t1->id}"], $keys(['selections' => [['type' => 'playlist', 'id' => $playlist->id]]]));
        $this->assertEqualsCanonicalizing(["track:{$t1->id}", "track:{$t2->id}"], $keys(['selections' => [['type' => 'artist', 'id' => $a->artist_id]]]));

        $b->forceFill(['favorited_at' => now()])->save();
        $this->assertEqualsCanonicalizing(["track:{$t3->id}", "track:{$t4->id}"], $keys(['selections' => [['type' => 'favorites']]]));

        // The first selection that has a track takes it; a missing id is skipped with a warning, not an error.
        $id = $this->target(['selections' => [['type' => 'album', 'id' => 9999], ['type' => 'playlist', 'id' => $playlist->id], ['type' => 'album', 'id' => $a->id]]]);
        $plan = $this->plan($id)->assertOk();
        $this->assertSame(["track:{$t4->id}", "track:{$t1->id}", "track:{$t2->id}"], array_column($plan->json('add'), 'key'));
        $this->assertSame('selection_not_found', $plan->json('warnings.0.code'));

        $t1->forceFill(['created_at' => now()->subDays(40)])->save();
        $t2->forceFill(['created_at' => now()->subDays(1)])->save();
        $t3->forceFill(['created_at' => now()->subDays(2)])->save();
        $t4->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->assertSame(["track:{$t2->id}", "track:{$t3->id}"], $keys(['selections' => [['type' => 'recently_added', 'limit' => 2]]]));
        $this->assertSame(["track:{$t2->id}", "track:{$t3->id}", "track:{$t4->id}"], $keys(['selections' => [['type' => 'recently_added', 'days' => 10]]]));

        foreach ([[$t3, 5], [$t1, 2], [$t2, 1]] as [$t, $n]) {
            for ($i = 0; $i < $n; $i++) {
                Play::create(['device_id' => \App\Models\Device::factory()->create()->id, 'track_id' => $t->id, 'played_at' => now()->subDays(2)]);
            }
        }
        $this->assertSame(["track:{$t3->id}", "track:{$t1->id}"], $keys(['selections' => [['type' => 'most_played', 'limit' => 2]]]));
    }

    public function test_removal_move_and_replace_against_what_the_target_holds(): void
    {
        $album = $this->album();
        $one = $this->track($album, 'One', number: 1);
        $two = $this->track($album, 'Two', number: 2);
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]]]);
        $have = $this->applied($this->plan($id)->assertOk()->json());

        // A rename on the server is a move on the target, no download.
        $one->forceFill(['name' => 'Uno'])->save();
        $plan = $this->plan($id, $have)->assertOk();
        $plan->assertJsonCount(0, 'add')->assertJsonCount(1, 'move')->assertJsonPath('move.0.path', 'Band/LP/01 - Uno.mp3')->assertJsonPath('move.0.from_path', 'Band/LP/01 - One.mp3');

        // A better rip (other content) replaces the file.
        $file = $two->trackFiles()->first()->file;
        $file->forceFill(['hash' => hash('sha256', 'better')])->save();
        $this->plan($id, $have)->assertOk()->assertJsonCount(1, 'replace')->assertJsonPath('replace.0.key', "track:{$two->id}")->assertJsonPath('replace.0.from_path', 'Band/LP/02 - Two.mp3');

        // A track that leaves the selection is removed (and only an entry of the target's own manifest can be).
        $one->forceFill(['album_id' => null])->save();
        $stranger = ['key' => 'track:999', 'fingerprint' => 'x', 'path' => 'Old/old.mp3', 'size' => 10];
        $plan = $this->plan($id, [...$have, $stranger])->assertOk();
        $this->assertEqualsCanonicalizing(["track:{$one->id}", 'track:999'], array_column($plan->json('remove'), 'key'));
    }

    public function test_sticky_paths_and_collisions_never_renumber_existing_files(): void
    {
        $album = $this->album();
        $x = $this->track($album, 'Same', number: 1);
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]]]);
        $have = $this->applied($this->plan($id)->json());

        $y = $this->track($album, 'Same', number: 1);
        $plan = $this->plan($id, $have)->assertOk();
        $plan->assertJsonCount(1, 'add')->assertJsonPath('add.0.key', "track:{$y->id}")->assertJsonPath('add.0.path', 'Band/LP/01 - Same (2).mp3');
        $have = $this->applied($plan->json(), $have);

        // Removing the first does not renumber the second: its "(2)" name still counts as the same name.
        $x->forceFill(['album_id' => null])->save();
        $plan = $this->plan($id, $have)->assertOk();
        $plan->assertJsonCount(0, 'move')->assertJsonCount(1, 'remove');
    }

    public function test_budget_fill_all_refuses_over_budget_with_the_estimate(): void
    {
        $album = $this->album();
        $this->track($album, 'One', size: 6000, number: 1);
        $this->track($album, 'Two', size: 6000, number: 2);
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]], 'budget' => ['bytes' => 10000, 'reserve_percent' => 0], 'fill' => 'all']);

        $this->plan($id)->assertStatus(422)->assertJsonPath('error', 'over_budget')->assertJsonPath('estimate.bytes', 12000)->assertJsonPath('estimate.over_budget', true);
        $this->postJson("/api/admin/sync/targets/{$id}/estimate", [], $this->auth())->assertOk()->assertJsonPath('over_budget', true)->assertJsonPath('tracks', 2);
        $this->assertSame(0, \App\Models\SyncArtifact::count());
    }

    public function test_priority_fill_keeps_whole_albums_and_respects_the_order(): void
    {
        $a = $this->album('A', 'Alpha');
        $b = $this->album('B', 'Beta');
        $c = $this->album('C', 'Gamma');
        $this->track($a, 'a1', size: 4000, number: 1);
        $this->track($a, 'a2', size: 4000, number: 2);
        $this->track($b, 'b1', size: 4000, number: 1);
        $this->track($b, 'b2', size: 4000, number: 2);
        $small = $this->track($c, 'c1', size: 1000, number: 1);

        // 12 kB: A (8) fits, B (8) does not and stops the selection list order... B is its own selection, so C still tries.
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $a->id], ['type' => 'album', 'id' => $b->id], ['type' => 'album', 'id' => $c->id]],
            'budget' => ['bytes' => 12000, 'reserve_percent' => 0], 'fill' => 'priority']);
        $plan = $this->plan($id)->assertOk();
        $keys = array_column($plan->json('add'), 'key');
        $this->assertCount(3, $keys);
        $this->assertNotContains('track:'.\App\Models\Media\Track::where('name', 'b1')->value('id'), $keys);
        $this->assertContains("track:{$small->id}", $keys);
        $this->assertLessThanOrEqual(12000, $plan->json('totals.wanted_bytes'));
    }

    public function test_priority_fill_is_sticky_new_space_goes_to_the_best_ranked_new_tracks(): void
    {
        $album = $this->album();
        $tracks = [];
        foreach (['t1', 't2', 't3', 't4'] as $i => $n) {
            $tracks[$n] = $this->track($album, $n, size: 1000, number: $i + 1);
        }
        $playlist = Playlist::create(['name' => 'Mix']);
        $playlist->tracks()->attach([$tracks['t1']->id => ['position' => 1], $tracks['t2']->id => ['position' => 2], $tracks['t3']->id => ['position' => 3]]);
        $id = $this->target(['selections' => [['type' => 'playlist', 'id' => $playlist->id]], 'budget' => ['bytes' => 2000, 'reserve_percent' => 0], 'fill' => 'priority', 'max_churn_percent' => 0]);

        $have = $this->applied($this->plan($id)->assertOk()->assertJsonCount(2, 'add')->json());
        $this->assertSame(["track:{$tracks['t1']->id}", "track:{$tracks['t2']->id}"], array_column($have, 'key'));

        // A new best-ranked track does not push out what is already there: only new space would take it.
        $playlist->tracks()->sync([$tracks['t4']->id => ['position' => 1], $tracks['t1']->id => ['position' => 2], $tracks['t2']->id => ['position' => 3], $tracks['t3']->id => ['position' => 4]]);
        $this->plan($id, $have)->assertOk()->assertJsonCount(0, 'add')->assertJsonCount(0, 'remove');

        // The budget forces something out: the lowest ranked track that is on the target goes first.
        $this->putJson("/api/admin/sync/targets/{$id}", ['rules' => ['selections' => [['type' => 'playlist', 'id' => $playlist->id]], 'budget' => ['bytes' => 1000, 'reserve_percent' => 0], 'fill' => 'priority', 'max_churn_percent' => 0]], $this->auth());
        $plan = $this->plan($id, $have)->assertOk();
        $this->assertSame(["track:{$tracks['t2']->id}"], array_column($plan->json('remove'), 'key'));
    }

    public function test_fill_rest_uses_favourites_most_played_and_newest_for_the_remaining_space(): void
    {
        $album = $this->album();
        $fav = $this->album('Fav', 'Loved');
        $one = $this->track($album, 'one', size: 1000, number: 1);
        $this->track($fav, 'f1', size: 1000, number: 1);
        $fav->forceFill(['favorited_at' => now()])->save();
        $playlist = Playlist::create(['name' => 'Mix']);
        $playlist->tracks()->attach([$one->id => ['position' => 1]]);
        $id = $this->target(['selections' => [['type' => 'playlist', 'id' => $playlist->id]], 'budget' => ['bytes' => 5000, 'reserve_percent' => 0], 'fill' => 'fill_rest']);

        $plan = $this->plan($id)->assertOk();
        $this->assertSame(2, count($plan->json('add')));
        $this->assertSame("track:{$one->id}", $plan->json('add.0.key')); // the explicit selection first
    }

    public function test_churn_limit_defers_new_units_but_never_starves_the_first(): void
    {
        $album = $this->album();
        $old = $this->track($album, 'old', size: 1000, number: 1);
        $playlist = Playlist::create(['name' => 'Mix']);
        $playlist->tracks()->attach([$old->id => ['position' => 1]]);
        $id = $this->target(['selections' => [['type' => 'playlist', 'id' => $playlist->id]], 'max_churn_percent' => 25]);
        $have = $this->applied($this->plan($id)->assertOk()->json());
        $version = SyncTarget::find($id)->rules_version;

        $new = [];
        foreach ([2, 3, 4] as $n) {
            $new[$n] = $this->track($album, "n{$n}", size: 400, number: $n);
        }
        $playlist->tracks()->attach(collect($new)->mapWithKeys(fn ($t, $n) => [$t->id => ['position' => $n]])->all());

        // 25 % of the 1000 bytes it owns = 250: the first new track goes through, the rest wait for the next sync.
        $plan = $this->plan($id, $have, ['rules_version' => $version])->assertOk();
        $plan->assertJsonCount(1, 'add')->assertJsonPath('deferred.count', 2);
        // Without the manifest's rules version nothing is held back (a person edited the rules: take it all).
        $this->plan($id, $have)->assertOk()->assertJsonCount(3, 'add')->assertJsonPath('deferred.count', 0);
    }

    public function test_mass_removal_and_an_empty_selection_need_a_confirmation(): void
    {
        $album = $this->album();
        $have = [];
        foreach (range(1, 10) as $n) {
            $have[] = ['key' => "track:{$n}0000", 'fingerprint' => 'f', 'path' => "x/{$n}.mp3", 'size' => 100];
        }
        $this->track($album, 'keep', number: 1);
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]]]);
        $this->plan($id, $have)->assertOk()->assertJsonPath('confirm_required', true)->assertJsonCount(10, 'remove');

        $empty = $this->target(['selections' => []], 'Empty');
        $this->plan($empty, $have)->assertOk()->assertJsonPath('confirm_required', true);
        $this->plan($empty)->assertStatus(422)->assertJsonPath('error', 'nothing_to_sync');
    }

    public function test_a_file_too_large_for_fat32_is_skipped_on_a_fat_target(): void
    {
        config(['sync.fat32_max_bytes' => 5000]);
        $album = $this->album();
        $this->track($album, 'small', size: 4000, number: 1);
        $this->track($album, 'big', size: 9000, number: 2);
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]]]);

        $this->plan($id, [], ['fs' => 'vfat'])->assertOk()->assertJsonCount(1, 'add')->assertJsonPath('warnings.0.code', 'too_large_for_fat32');
        $this->plan($id, [], ['fs' => 'ext4'])->assertOk()->assertJsonCount(2, 'add');
    }

    public function test_free_space_caps_the_budget_and_asks_to_remove_first(): void
    {
        $album = $this->album();
        $this->track($album, 'One', size: 4000, number: 1);
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]]]);
        $have = [['key' => 'track:424242', 'fingerprint' => 'f', 'path' => 'old.mp3', 'size' => 9000]];

        $plan = $this->plan($id, $have, ['free_bytes' => 1000])->assertOk();
        $plan->assertJsonPath('remove_first', true)->assertJsonCount(1, 'remove')->assertJsonCount(1, 'add');
    }

    public function test_have_is_validated_cheaply(): void
    {
        $id = $this->target(['selections' => [['type' => 'favorites']]]);
        $this->plan($id, [['key' => 'a', 'fingerprint' => 'f', 'path' => 'p'], ['key' => 'a', 'fingerprint' => 'f', 'path' => 'q']])->assertStatus(422);
        $this->plan($id, [['key' => 'a']])->assertStatus(422);
        $this->postJson("/api/admin/sync/targets/{$id}/plan", [], $this->auth())->assertStatus(422);
    }

    public function test_copy_downloads_are_signed_ranged_and_bound_to_the_fingerprint(): void
    {
        $album = $this->album();
        $track = $this->track($album, 'One', size: 3000, number: 1);
        $id = $this->target(['selections' => [['type' => 'album', 'id' => $album->id]]]);
        $add = $this->plan($id)->assertOk()->json('add.0');
        $bytes = file_get_contents($this->library.'/'.$track->trackFiles()->first()->file->path);

        $full = $this->get($add['url'])->assertOk();
        $full->assertHeader('Accept-Ranges', 'bytes')->assertHeader('X-Sync-Sha256', hash('sha256', $bytes));
        $this->assertSame($bytes, $full->baseResponse->getFile()->getContent());

        $part = $this->get($add['url'], ['Range' => 'bytes=100-199'])->assertStatus(206);
        $part->assertHeader('Content-Range', 'bytes 100-199/3000');

        $this->get(preg_replace('/signature=.*/', 'signature=nope', $add['url']))->assertStatus(403);
        $this->get(str_replace($add['fingerprint'], str_repeat('0', 64), $add['url']))->assertStatus(403); // the signature covers the fingerprint
        // The admin token route serves the same file with a header instead of a signature.
        $this->get("/api/admin/sync/files/{$id}/{$add['key']}/{$add['fingerprint']}", $this->auth())->assertOk();
        $this->get("/api/admin/sync/files/{$id}/{$add['key']}/{$add['fingerprint']}")->assertStatus(401);
        // A fingerprint that is no longer the right one is stale (the executor re-plans).
        $this->get("/api/admin/sync/files/{$id}/{$add['key']}/".str_repeat('0', 64), $this->auth())->assertStatus(404)->assertJsonPath('error', 'stale');
    }

    public function test_node_routes_are_bound_to_the_nodes_own_target(): void
    {
        $album = $this->album();
        $this->track($album, 'One');
        $mine = Client::create(['name' => 'N1', 'type' => 'single', 'kind' => 'node', 'status' => 'approved', 'roles' => ['sync-target'], 'enabled_roles' => ['sync-target'], 'api_token' => 'node-token-1', 'hardware_id' => 'h1', 'registration_token' => 'r1']);
        $other = Client::create(['name' => 'N2', 'type' => 'single', 'kind' => 'node', 'status' => 'approved', 'roles' => ['sync-target'], 'enabled_roles' => ['sync-target'], 'api_token' => 'node-token-2', 'hardware_id' => 'h2', 'registration_token' => 'r2']);
        $rules = ['selections' => [['type' => 'album', 'id' => $album->id]]];
        $target = $this->postJson('/api/admin/sync/targets', ['name' => 'Stick', 'kind' => 'node', 'node_id' => $mine->id, 'rules' => $rules], $this->auth())->assertCreated()->json('id');
        $this->postJson('/api/admin/sync/targets', ['name' => 'Again', 'kind' => 'node', 'node_id' => $mine->id], $this->auth())->assertStatus(422);

        $this->postJson('/api/nodes/sync/plan', ['target_id' => $target, 'have' => []])->assertStatus(401);
        $this->postJson('/api/nodes/sync/plan', ['target_id' => $target, 'have' => []], ['Authorization' => 'Bearer node-token-2'])->assertStatus(403);
        $this->postJson('/api/nodes/sync/plan', ['target_id' => $target, 'have' => []], ['Authorization' => 'Bearer node-token-1'])->assertOk()->assertJsonCount(1, 'add');
        $this->postJson('/api/admin/sync/targets', ['name' => 'X', 'kind' => 'node', 'node_id' => $other->id + 99], $this->auth())->assertStatus(422);

        $this->postJson('/api/nodes/sync/report', ['target_id' => $target, 'applied' => ['track:1'], 'failed' => [['key' => 'track:2', 'error' => 'boom']], 'owned_bytes' => 4000, 'owned_count' => 1, 'free_bytes' => 99], ['Authorization' => 'Bearer node-token-1'])->assertOk();
        $row = SyncTarget::find($target);
        $this->assertSame(4000, $row->owned_bytes);
        $this->assertSame(1, $row->last_report['applied']);
        $this->assertSame('boom', $row->last_report['failures'][0]['error']);
        $this->postJson('/api/nodes/sync/report', ['target_id' => $target], ['Authorization' => 'Bearer node-token-2'])->assertStatus(403);
    }
}
