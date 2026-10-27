<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\DeviceStateChanged;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Listeners\Device\SyncGroupMirror;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\SyncGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeMovableDriver;
use Tests\Support\FakePlayerDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** Sync groups ("fake multiroom"): a leader and members kept together by the server. */
class SyncGroupTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Device $leader;

    private Device $member;

    private Track $track;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        FakePlayerDriver::reset();
        config(['playback.move_wait_seconds' => 0]);

        $this->leader = Device::factory()->driver(FakeMovableDriver::class)->inState(State::Playing)->create(['device_name' => 'Kitchen']);
        $this->member = Device::factory()->driver(FakeMovableDriver::class)->inState(State::Playing)->create(['device_name' => 'Bedroom']);
        $album = Album::create(['artist_id' => Artist::create(['name' => 'Artist'])->id, 'name' => 'Album']);
        $this->track = $this->makeLibraryTrack($album, 'Song');
    }

    private function np(string $name = 'Song', int $position = 30): NowPlaying
    {
        return new NowPlaying(
            track: new TrackData(name: $name, source: 'files', artist: new ArtistData('Artist'), duration: 200),
            album: new AlbumData(name: 'Album'),
            state: 'playing',
            position: $position,
        );
    }

    private function calls(string $method): array
    {
        return array_values(array_filter(FakePlayerDriver::$calls, fn ($call) => $call[0] === $method));
    }

    private function group(): SyncGroup
    {
        $this->postJson('/api/sync-groups', ['leader_device_id' => $this->leader->id, 'member_device_ids' => [$this->member->id]])->assertCreated();

        return SyncGroup::firstOrFail();
    }

    private function mirror(object $event): void
    {
        app(SyncGroupMirror::class)->handle($event);
    }

    public function test_creating_a_group_starts_the_members_where_the_leader_is(): void
    {
        DeviceCache::updateNowPlaying($this->leader->id, $this->np(position: 30));

        $this->postJson('/api/sync-groups', ['leader_device_id' => $this->leader->id, 'member_device_ids' => [$this->member->id], 'name' => 'Downstairs'])
            ->assertCreated()
            ->assertJson(['data' => ['name' => 'Downstairs', 'leader' => ['id' => $this->leader->id], 'members' => [['id' => $this->member->id, 'offset_ms' => 0]]], 'failed' => []]);

        $this->assertSame([$this->track->id], $this->calls('playLibraryTracks')[0][1]);
        $this->assertSame(30, $this->calls('seek')[0][1]);
        $this->assertEmpty($this->calls('stop'), 'the leader keeps playing');
    }

    public function test_a_group_made_while_the_leader_is_silent_starts_nothing(): void
    {
        DeviceCache::updateState($this->leader->id, State::Standby);

        $this->postJson('/api/sync-groups', ['leader_device_id' => $this->leader->id, 'member_device_ids' => [$this->member->id]])->assertCreated();

        $this->assertEmpty($this->calls('playLibraryTracks'));
    }

    public function test_a_device_is_in_one_group_only_and_members_must_be_able_to_follow(): void
    {
        $this->group();
        $other = Device::factory()->driver(FakeMovableDriver::class)->create();
        $dumb = Device::factory()->create();

        $this->postJson('/api/sync-groups', ['leader_device_id' => $other->id, 'member_device_ids' => [$this->member->id]])->assertStatus(422)->assertJson(['error' => 'invalid']);
        $this->postJson('/api/sync-groups', ['leader_device_id' => $other->id, 'member_device_ids' => [$other->id]])->assertStatus(422);
        $this->postJson('/api/sync-groups', ['leader_device_id' => $other->id, 'member_device_ids' => [$dumb->id]])->assertStatus(422);
        $this->assertSame(1, SyncGroup::count());
    }

    public function test_spotify_cannot_be_shared(): void
    {
        DeviceCache::updateNowPlaying($this->leader->id, $this->np());
        DeviceCache::setSpotifyRoutedDevice($this->leader->id);
        Device::factory()->driver(\App\Integrations\Spotify\MusicPlayerDriver::class, 'Spotify')->inState(State::Standby)->create();

        $this->postJson('/api/sync-groups', ['leader_device_id' => $this->leader->id, 'member_device_ids' => [$this->member->id]])
            ->assertCreated()
            ->assertJsonPath('failed.0.device_id', $this->member->id);

        $this->assertEmpty($this->calls('playLibraryTracks'));
    }

    public function test_a_member_that_drifted_is_seeked_back(): void
    {
        $group = $this->group();
        DeviceCache::updateNowPlaying($this->leader->id, $this->np(position: 100));
        DeviceCache::updateNowPlaying($this->member->id, $this->np(position: 95));
        FakePlayerDriver::reset();

        $this->mirror(new ProgressUpdated((string) $this->leader->id, 100));

        $this->assertSame([['seek', 100]], $this->calls('seek'));

        // Left alone while its reported position catches up.
        Cache::forget("sync:{$group->id}:tick");
        $this->mirror(new ProgressUpdated((string) $this->leader->id, 101));
        $this->assertCount(1, $this->calls('seek'));
    }

    public function test_a_member_within_the_threshold_is_left_alone_and_the_offset_counts(): void
    {
        $group = $this->group();
        DeviceCache::updateNowPlaying($this->leader->id, $this->np(position: 100));
        DeviceCache::updateNowPlaying($this->member->id, $this->np(position: 101));
        FakePlayerDriver::reset();

        $this->mirror(new ProgressUpdated((string) $this->leader->id, 100));
        $this->assertEmpty($this->calls('seek'));

        // A +5 s trim: the member should be 5 s ahead, so 101 is now off.
        $this->putJson("/api/sync-groups/{$group->id}/members/{$this->member->id}", ['offset_ms' => 5000])->assertOk()->assertJson(['offset_ms' => 5000]);
        Cache::forget("sync:{$group->id}:tick");
        $this->mirror(new ProgressUpdated((string) $this->leader->id, 100));
        $this->assertSame([['seek', 105]], $this->calls('seek'));
    }

    public function test_the_leaders_pause_resume_and_standby_are_replayed(): void
    {
        $this->group();
        FakePlayerDriver::reset();

        $this->mirror(new DeviceStateChanged($this->leader->id, State::Paused, State::Playing));
        $this->mirror(new DeviceStateChanged($this->leader->id, State::Playing, State::Paused));
        $this->mirror(new DeviceStateChanged($this->leader->id, State::Standby, State::Playing));
        $this->mirror(new DeviceStateChanged($this->leader->id, State::Unreachable, State::Playing));

        $this->assertSame(['pause', 'play', 'stop'], array_column(FakePlayerDriver::$calls, 0));
    }

    public function test_a_member_that_did_not_follow_a_track_change_is_restarted(): void
    {
        $this->group();
        $next = $this->makeLibraryTrack(Album::first(), 'Next');
        DeviceCache::updateNowPlaying($this->leader->id, $this->np('Next', 0));
        DeviceCache::updateNowPlaying($this->member->id, $this->np('Song', 199));
        FakePlayerDriver::reset();

        $this->mirror(new NowPlayingUpdated((string) $this->leader->id, $this->np('Next', 0), 'media'));

        $this->assertSame([$next->id], $this->calls('playLibraryTracks')[0][1]);
    }

    public function test_a_member_that_moved_on_by_itself_is_not_touched(): void
    {
        $this->group();
        DeviceCache::updateNowPlaying($this->leader->id, $this->np('Next', 0));
        DeviceCache::updateNowPlaying($this->member->id, $this->np('Next', 1));
        FakePlayerDriver::reset();

        $this->mirror(new NowPlayingUpdated((string) $this->leader->id, $this->np('Next', 0), 'media'));

        $this->assertEmpty(FakePlayerDriver::$calls);
    }

    public function test_events_of_devices_outside_a_group_are_ignored(): void
    {
        $this->group();
        FakePlayerDriver::reset();

        $this->mirror(new DeviceStateChanged($this->member->id, State::Standby, State::Playing));

        $this->assertEmpty(FakePlayerDriver::$calls);
    }

    public function test_dissolving_stops_the_members_and_the_mirror_forgets_the_leader(): void
    {
        $group = $this->group();
        FakePlayerDriver::reset();

        $this->deleteJson("/api/sync-groups/{$group->id}")->assertOk();

        $this->assertSame([['stop', null]], $this->calls('stop'));
        $this->assertSame(0, SyncGroup::count());

        FakePlayerDriver::reset();
        $this->mirror(new DeviceStateChanged($this->leader->id, State::Standby, State::Playing));
        $this->assertEmpty(FakePlayerDriver::$calls);
    }

    public function test_removing_the_last_member_ends_the_group(): void
    {
        $group = $this->group();

        $this->deleteJson("/api/sync-groups/{$group->id}/members/{$this->member->id}", ['stop' => false])->assertOk();

        $this->assertSame(0, SyncGroup::count());
    }

    public function test_lists_groups_and_adds_a_member(): void
    {
        $group = $this->group();
        $third = Device::factory()->driver(FakeMovableDriver::class)->inState(State::Playing)->create(['device_name' => 'Bathroom']);

        $this->postJson("/api/sync-groups/{$group->id}/members", ['device_id' => $third->id])->assertCreated()->assertJsonCount(2, 'data.members');
        $this->getJson('/api/sync-groups')->assertOk()->assertJsonPath('data.0.leader.name', 'Kitchen')->assertJsonCount(2, 'data.0.members');
        $this->assertContains('sync_group', $this->getJson("/api/devices/{$third->id}")->json('data.capabilities'));
    }
}
