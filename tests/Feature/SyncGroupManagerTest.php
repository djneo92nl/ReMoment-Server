<?php

namespace Tests\Feature;

use App\Domain\Device\State;
use App\Livewire\SyncGroupManager;
use App\Models\Device;
use App\Models\SyncGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeMovableDriver;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

class SyncGroupManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_multiroom_page_has_the_sync_groups_section(): void
    {
        $this->get(route('multiroom.index'))->assertOk()->assertSee('Sync groups');
    }

    public function test_a_group_is_made_trimmed_and_ended_from_the_page(): void
    {
        FakePlayerDriver::reset();
        $leader = Device::factory()->driver(FakeMovableDriver::class)->inState(State::Standby)->create(['device_name' => 'Kitchen']);
        $member = Device::factory()->driver(FakeMovableDriver::class)->inState(State::Standby)->create(['device_name' => 'Bedroom']);

        $page = Livewire::test(SyncGroupManager::class)
            ->set('creating', true)
            ->assertSee('Kitchen')
            ->set('leaderId', $leader->id)
            ->set('memberIds', [$member->id])
            ->call('create')
            ->assertSet('feedbackIsError', false)
            ->assertSee('Kitchen leads');

        $group = SyncGroup::firstOrFail();

        $page->call('nudge', $group->id, $member->id, 250)->assertSee('+250 ms');
        $page->call('dissolve', $group->id);

        $this->assertSame(0, SyncGroup::count());
    }

    public function test_a_group_needs_a_leader_and_a_member(): void
    {
        Livewire::test(SyncGroupManager::class)->set('creating', true)->call('create')->assertHasErrors(['leaderId', 'memberIds']);
    }
}
