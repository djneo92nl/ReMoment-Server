<?php

namespace Tests\Feature;

use App\Domain\Sync\Syncs;
use App\Livewire\SyncTargets;
use App\Models\Client;
use App\Models\Media\Playlist;
use App\Models\NodeJob;
use App\Models\SyncTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\MakesSyncLibrary;
use Tests\TestCase;

/** The Sync page under Tools: admin only, add a drive on a node, the rules form, estimate, scan, claim, sync now and the last result. */
class SyncPageTest extends TestCase
{
    use MakesSyncLibrary, RefreshDatabase;

    private Client $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeLibrary();
        $this->node = Client::create(['name' => 'Pi', 'type' => 'single', 'kind' => 'node', 'status' => 'approved', 'roles' => ['sync-target'], 'enabled_roles' => ['sync-target'],
            'api_token' => 'node-token', 'hardware_id' => 'h1', 'registration_token' => 'r1']);
    }

    protected function tearDown(): void
    {
        $this->removeLibrary();
        parent::tearDown();
    }

    public function test_guests_see_the_login_hint_and_cannot_use_the_component(): void
    {
        $this->get('/tools/sync')->assertOk()->assertSee('Sync needs the admin login')->assertDontSee('data-test="sync-targets"', false);
        Livewire::test(SyncTargets::class)->assertForbidden();
    }

    public function test_the_page_is_in_the_standard_profile_and_not_in_control(): void
    {
        $this->actingAs(User::factory()->create());
        config(['profile.current' => 'standard']);
        $this->get('/tools/sync')->assertOk()->assertSee('data-test="sync-targets"', false);
        config(['profile.current' => 'control']);
        $this->get('/tools/sync')->assertRedirectContains('/not-in-profile');
    }

    public function test_add_a_drive_edit_rules_estimate_and_sync(): void
    {
        $this->actingAs(User::factory()->create());
        $album = $this->album();
        $this->track($album, 'One', size: 2000);
        $playlist = Playlist::create(['name' => 'Road trip']);
        $playlist->tracks()->attach([\App\Models\Media\Track::first()->id => ['position' => 1]]);

        $c = Livewire::test(SyncTargets::class)->assertSee('No sync target yet')
            ->set('newName', 'Car stick')->set('newNode', $this->node->id)->call('create')
            ->assertSet('error', null)->assertSee('Car stick')->assertSee('rules-form', false);
        $target = SyncTarget::first();
        $this->assertSame($this->node->id, $target->node_id);

        $c->set('draft.playlists', [(string) $playlist->id])->set('draft.budget_gb', '1')->set('draft.fill', 'priority')->set('draft.profile', 'mp3-192')->set('draft.loudness', 'album')
            ->call('save')->assertSet('error', null)->assertSet('editing', null)->assertSee('playlist Road trip');
        $rules = $target->fresh()->rules;
        $this->assertSame([['type' => 'playlist', 'id' => $playlist->id]], $rules['selections']);
        $this->assertSame(1000000000, $rules['budget']['bytes']);
        $this->assertSame('album', $rules['options']['loudness']);
        $this->assertSame(2, $target->fresh()->rules_version);

        $c->call('estimate', $target->id)->assertSee('1 tracks selected');

        $c->call('scan', $target->id)->assertSee('Reading the drive');
        $scan = Syncs::lastJob($target, 'sync.scan');
        $this->assertNotNull($scan);
        // The node answers: an unmarked drive. The page offers "Use this drive" and nothing was claimed by itself.
        NodeJob::whereKey($scan->id)->update(['state' => 'succeeded', 'result' => json_encode(['mounted' => true, 'fs' => 'vfat', 'size' => 8000000000, 'free' => 7000000000, 'marker' => null, 'manifest' => null]), 'finished_at' => now()]);
        SyncTarget::whereKey($target->id)->update(['active_job_id' => null]);
        $c->call('$refresh')->assertSee('Not used by ReMoment yet')->assertSeeHtml('data-test="claim"');
        $this->assertSame(0, NodeJob::where('type', 'sync.claim')->count());
        $c->call('claim', $target->id);
        $this->assertSame(1, NodeJob::where('type', 'sync.claim')->count());
        SyncTarget::whereKey($target->id)->update(['active_job_id' => null]);

        $c->call('sync', $target->id); $c->assertSee('Sync queued');
        $job = NodeJob::where('type', 'sync.apply')->first();
        $this->assertSame(['target_id' => $target->id, 'confirm' => false], $job->params);
        $c->assertSeeHtml('data-test="running"')->assertSeeHtml('data-test="cancel-run"');

        // The result is shown on the page.
        NodeJob::whereKey($job->id)->update(['state' => 'succeeded', 'result' => json_encode(['state' => 'done', 'applied' => 1, 'removed' => 0, 'failed' => 0]), 'finished_at' => now(), 'accepted_at' => now()]);
        Syncs::finished($job->fresh());
        $c->call('$refresh')->assertSee('Succeeded')->assertSee('1 added or replaced');
    }

    public function test_a_second_sync_while_one_runs_is_refused_with_a_note_and_a_drive_can_only_be_added_once(): void
    {
        $this->actingAs(User::factory()->create());
        $target = SyncTarget::create(['name' => 'Stick', 'kind' => 'node', 'node_id' => $this->node->id, 'rules' => ['selections' => []]]);
        $c = Livewire::test(SyncTargets::class)->call('sync', $target->id)->call('sync', $target->id)->assertSee('already running');
        $this->assertSame(1, NodeJob::where('type', 'sync.apply')->count());

        // The node already has a target: it is no longer offered.
        $c->assertDontSee('Choose a node')->assertSee('No node with the sync-target role is available');
        $c->set('newName', 'Again')->set('newNode', $this->node->id)->call('create')->assertSee('one stick per node');
    }

    public function test_rules_the_form_does_not_know_survive_a_save(): void
    {
        $this->actingAs(User::factory()->create());
        $album = $this->album();
        $target = SyncTarget::create(['name' => 'Stick', 'kind' => 'node', 'node_id' => $this->node->id, 'rules' => ['selections' => [['type' => 'album', 'id' => $album->id]]]]);

        Livewire::test(SyncTargets::class)->call('edit', $target->id)->set('draft.favorites', true)->call('save')->assertSet('error', null);
        $this->assertSame([['type' => 'album', 'id' => $album->id], ['type' => 'favorites']], $target->fresh()->rules['selections']);
    }
}
