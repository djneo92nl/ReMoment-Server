<?php

namespace Tests\Feature\Mcp;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\AdminToken;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\FakePlayerDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The MCP server wraps the REST API and the library domain classes (docs/api/mcp.md). */
class ReMomentMcpTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
        FakeLibraryPlaybackDriver::reset();
    }

    private function device(string $driver = FakePlayerDriver::class, State $state = State::Standby, string $name = 'Living Room'): Device
    {
        $device = Device::factory()->create(['device_name' => $name, 'device_driver' => $driver, 'ip_address' => '10.0.0.1']);
        DeviceCache::updateState($device->id, $state);

        return $device;
    }

    private function asAdmin(): void
    {
        User::factory()->create();
        [, $plain] = AdminToken::issue('test');
        request()->headers->set('Authorization', 'Bearer '.$plain);
    }

    public function test_list_devices_returns_the_api_list(): void
    {
        $device = $this->device();

        ReMomentServer::tool(Tools\ListDevices::class)
            ->assertOk()
            ->assertSee(['Living Room', '"id":'.$device->id]);
    }

    public function test_playback_control_goes_through_the_driver(): void
    {
        $device = $this->device();

        ReMomentServer::tool(Tools\PlaybackControl::class, ['device_id' => $device->id, 'action' => 'play'])
            ->assertOk()->assertSee('"action":"play"');
        ReMomentServer::tool(Tools\PlaybackControl::class, ['device_id' => $device->id, 'action' => 'seek', 'position' => 90])
            ->assertOk()->assertSee('"position":90');
    }

    public function test_api_errors_come_back_as_tool_errors_with_the_api_code(): void
    {
        $unreachable = $this->device(state: State::Unreachable, name: 'Gone');
        $bare = $this->device(FakeBareDriver::class, name: 'Bare');

        ReMomentServer::tool(Tools\PlaybackControl::class, ['device_id' => $unreachable->id, 'action' => 'play'])
            ->assertHasErrors(['unreachable (503)']);
        ReMomentServer::tool(Tools\Volume::class, ['device_id' => $bare->id, 'volume' => 20])
            ->assertHasErrors(['unsupported (422)']);
    }

    public function test_the_queue_tool_edits_the_queue_through_the_api(): void
    {
        $device = $this->device(\Tests\Support\FakeQueueEditDriver::class, State::Playing);
        $track = $this->makeLibraryTrack(Album::create(['name' => 'A', 'artist_id' => Artist::create(['name' => 'B'])->id]), 'One');

        ReMomentServer::tool(Tools\Queue::class, ['device_id' => $device->id])->assertOk()->assertSee('Next Song');
        ReMomentServer::tool(Tools\Queue::class, ['device_id' => $device->id, 'action' => 'add', 'type' => 'track', 'id' => $track->id])->assertOk()->assertSee('"tracks":1');
        ReMomentServer::tool(Tools\Queue::class, ['device_id' => $device->id, 'action' => 'next', 'type' => 'track', 'id' => $track->id])->assertOk()->assertSee('"next":true');
        ReMomentServer::tool(Tools\Queue::class, ['device_id' => $device->id, 'action' => 'remove', 'position' => 1, 'expect' => 'fake:1'])->assertOk();
        ReMomentServer::tool(Tools\Queue::class, ['device_id' => $device->id, 'action' => 'move', 'position' => 2, 'to' => 1])->assertOk();
        ReMomentServer::tool(Tools\Queue::class, ['device_id' => $device->id, 'action' => 'clear'])->assertHasErrors(['confirmation_required']);
        ReMomentServer::tool(Tools\Queue::class, ['device_id' => $device->id, 'action' => 'clear', 'confirm' => true])->assertOk();
        ReMomentServer::tool(Tools\Queue::class, ['device_id' => $device->id, 'action' => 'jump', 'position' => 2])->assertOk();

        $this->assertSame(
            [['addToQueue', [$track->id]], ['playNext', [$track->id]], ['removeFromQueue', [1, 'fake:1']], ['moveInQueue', [2, 1, null]], ['clearQueue', null], ['skipToQueuePosition', 2]],
            array_values(array_filter(FakePlayerDriver::$calls, fn ($call) => $call[0] !== 'getUpNext')),
        );
    }

    public function test_volume_reads_both_values_and_sets_them(): void
    {
        $device = $this->device();

        ReMomentServer::tool(Tools\Volume::class, ['device_id' => $device->id])
            ->assertOk()->assertSee(['"volume":30', '"muted":false']);
        ReMomentServer::tool(Tools\Volume::class, ['device_id' => $device->id, 'volume' => 45])
            ->assertOk()->assertSee('"volume":45');
        $this->assertContains(['setVolume', 45], FakePlayerDriver::$calls);
    }

    public function test_invalid_arguments_are_rejected(): void
    {
        $device = $this->device();

        ReMomentServer::tool(Tools\Volume::class, ['device_id' => $device->id, 'volume' => 101])->assertHasErrors();
    }

    public function test_library_search_and_play(): void
    {
        $artist = Artist::create(['name' => 'Muse', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Absolution', 'source' => 'dlna']);
        $track = $this->makeLibraryTrack($album, 'Hysteria');
        $device = $this->device(FakeLibraryPlaybackDriver::class);

        ReMomentServer::tool(Tools\SearchLibrary::class, ['query' => 'Muse'])->assertOk()->assertSee('Muse');
        ReMomentServer::tool(Tools\PlayFromLibrary::class, ['device_id' => $device->id, 'type' => 'track', 'id' => $track->id])
            ->assertOk()->assertSee('Hysteria');
        ReMomentServer::tool(Tools\SetFavorite::class, ['type' => 'album', 'id' => $album->id, 'favorite' => true])
            ->assertOk()->assertSee('"favorite":true');
        $this->assertNotNull($album->fresh()->favorited_at);
    }

    public function test_playlists_can_be_created_edited_and_deleted(): void
    {
        $artist = Artist::create(['name' => 'Muse', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Absolution', 'source' => 'dlna']);
        $a = $this->makeLibraryTrack($album, 'Hysteria');
        $b = $this->makeLibraryTrack($album, 'Sing for Absolution');

        ReMomentServer::tool(Tools\ManagePlaylist::class, ['action' => 'create', 'name' => 'Rock'])->assertOk();
        $playlist = Playlist::firstWhere('name', 'Rock');
        $this->assertSame('local', $playlist->source);

        ReMomentServer::tool(Tools\ManagePlaylist::class, ['action' => 'add_tracks', 'id' => $playlist->id, 'track_ids' => [$a->id, $b->id]])
            ->assertOk()->assertSee('"track_count":2');
        ReMomentServer::tool(Tools\ManagePlaylist::class, ['action' => 'move_track', 'id' => $playlist->id, 'track_id' => $b->id, 'direction' => -1])->assertOk();
        $this->assertSame([$b->id, $a->id], $playlist->tracks()->pluck('tracks.id')->all());

        ReMomentServer::tool(Tools\ManagePlaylist::class, ['action' => 'rename', 'id' => $playlist->id, 'name' => 'Rock 2'])->assertOk();
        $this->assertSame('Rock 2', $playlist->fresh()->name);

        ReMomentServer::tool(Tools\ManagePlaylist::class, ['action' => 'delete', 'id' => $playlist->id])->assertHasErrors(['confirmation_required']);
        $this->assertNotNull(Playlist::find($playlist->id));
        ReMomentServer::tool(Tools\ManagePlaylist::class, ['action' => 'delete', 'id' => $playlist->id, 'confirm' => true])->assertOk();
        $this->assertNull(Playlist::find($playlist->id));
    }

    public function test_spotify_playlists_cannot_be_edited(): void
    {
        $playlist = Playlist::create(['name' => 'Discover', 'source' => 'spotify']);

        ReMomentServer::tool(Tools\ManagePlaylist::class, ['action' => 'rename', 'id' => $playlist->id, 'name' => 'x'])
            ->assertHasErrors(['unsupported']);
    }

    public function test_admin_tools_need_the_admin_token(): void
    {
        ReMomentServer::tool(Tools\LibraryMaintenance::class, ['action' => 'sync_genres'])->assertNotRegistered();
        ReMomentServer::tool(Tools\Storage::class, ['action' => 'locations'])->assertNotRegistered();
        ReMomentServer::tool(Tools\RemoveLibraryItem::class, ['type' => 'track', 'id' => 1, 'confirm' => true])->assertNotRegistered();
    }

    public function test_admin_tools_work_with_the_token(): void
    {
        $this->asAdmin();

        ReMomentServer::tool(Tools\LibraryMaintenance::class, ['action' => 'merge_duplicates', 'dry_run' => true])
            ->assertOk()->assertSee('library:merge-duplicates');
        ReMomentServer::tool(Tools\LibraryMaintenance::class, ['action' => 'merge_duplicates'])
            ->assertHasErrors(['confirmation_required']);
        ReMomentServer::tool(Tools\Storage::class, ['action' => 'locations'])->assertOk();
        ReMomentServer::tool(Tools\Storage::class, ['action' => 'reorganize'])->assertHasErrors(['confirmation_required']);
    }

    public function test_removing_a_library_item_needs_confirmation(): void
    {
        $this->asAdmin();
        $artist = Artist::create(['name' => 'Muse', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Absolution', 'source' => 'dlna']);
        $track = $this->makeLibraryTrack($album, 'Hysteria', dlna: false, spotifyId: 'abc');
        $onDlna = $this->makeLibraryTrack($album, 'Uprising');

        ReMomentServer::tool(Tools\RemoveLibraryItem::class, ['type' => 'track', 'id' => $onDlna->id, 'confirm' => true])->assertHasErrors(['kept (409)']);
        $this->assertNotNull(Track::find($onDlna->id));
        ReMomentServer::tool(Tools\RemoveLibraryItem::class, ['type' => 'track', 'id' => $track->id, 'confirm' => false])->assertHasErrors();
        $this->assertNotNull(Track::find($track->id));
        ReMomentServer::tool(Tools\RemoveLibraryItem::class, ['type' => 'track', 'id' => $track->id, 'confirm' => true])->assertOk();
        $this->assertNull(Track::find($track->id));
    }
}
