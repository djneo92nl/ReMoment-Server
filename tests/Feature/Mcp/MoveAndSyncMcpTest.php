<?php

namespace Tests\Feature\Mcp;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeMovableDriver;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** The move_playback and sync_group tools wrap the REST API (docs/api/mcp.md). */
class MoveAndSyncMcpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
    }

    public function test_move_playback_answers_with_the_apis_refusal(): void
    {
        $from = Device::factory()->inState(State::Playing)->create();
        $to = Device::factory()->driver(FakeMovableDriver::class)->inState(State::Playing)->create();
        DeviceCache::updateNowPlaying($from->id, new NowPlaying(state: 'playing', source: new Source(name: 'TV', sourceType: 'HDMI')));

        ReMomentServer::tool(Tools\MovePlayback::class, ['device_id' => $from->id, 'to_device_id' => $to->id])
            ->assertHasErrors(['not_movable (422)']);
    }

    public function test_sync_group_create_list_and_dissolve(): void
    {
        $leader = Device::factory()->driver(FakeMovableDriver::class)->inState(State::Standby)->create(['device_name' => 'Kitchen']);
        $member = Device::factory()->driver(FakeMovableDriver::class)->inState(State::Standby)->create(['device_name' => 'Bedroom']);

        ReMomentServer::tool(Tools\SyncGroup::class, ['action' => 'create', 'device_id' => $leader->id, 'member_ids' => [$member->id]])
            ->assertOk()->assertSee('Kitchen');
        ReMomentServer::tool(Tools\SyncGroup::class, ['action' => 'list'])
            ->assertOk()->assertSee(['Kitchen', 'Bedroom']);
        ReMomentServer::tool(Tools\SyncGroup::class, ['action' => 'dissolve', 'group_id' => 1, 'stop_members' => false])
            ->assertOk();
        ReMomentServer::tool(Tools\SyncGroup::class, ['action' => 'list'])
            ->assertOk()->assertDontSee('Kitchen');
    }
}
