<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Library\LibraryPlayback;
use App\Events\Device\NowPlayingUpdated;
use App\Integrations\ReMoment\PlayerDevice;
use App\Integrations\ReMoment\PlayerQueue;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Services\MqttService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PlayerApiTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $extra = [], ?string $token = null): array
    {
        return $this->postJson('/api/players/register', ['name' => 'Kitchen browser', 'kind' => 'web', 'token' => $token] + $extra)->json();
    }

    private function ownTrack(string $name): Track
    {
        $artist = Artist::firstOrCreate(['name' => 'A', 'source' => 'dlna']);
        $album = Album::firstOrCreate(['artist_id' => $artist->id, 'name' => 'B', 'source' => 'dlna']);
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'external_id' => uniqid('f:', true), 'name' => $name, 'duration' => 100, 'source' => 'dlna']);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'dlna_url', 'value' => 'x', 'type' => 'url', 'source' => \App\Domain\Storage\FileIndexer::URL_SOURCE]);

        return $track;
    }

    public function test_register_creates_one_device_per_token(): void
    {
        $first = $this->register();
        $again = $this->register(token: $first['player_token']);

        $this->assertSame($first['device_id'], $again['device_id']);
        $this->assertSame($first['player_token'], $again['player_token']);
        $this->assertSame(1, Device::count());
        $this->assertSame("remoment/playercmd/{$first['device_id']}", $first['command_topic']);
        $this->assertSame(0, $first['last_command_id']);
    }

    public function test_player_reports_only_the_capabilities_it_registered_with(): void
    {
        $id = $this->register(['capabilities' => ['volume_control']])['device_id'];

        $capabilities = $this->getJson("/api/devices/{$id}")->json('data.capabilities');

        $this->assertContains('media_controls', $capabilities);
        $this->assertContains('volume_control', $capabilities);
        $this->assertNotContains('seek', $capabilities);
    }

    public function test_playing_a_library_track_sends_a_play_command_and_queues_the_rest(): void
    {
        $id = $this->register()['device_id'];
        $device = Device::find($id);
        DeviceCache::updateState($id, State::Standby);
        $tracks = collect(['One', 'Two', 'Three'])->map(fn ($n) => $this->ownTrack($n));

        $device->driver->playLibraryTracks($tracks);

        $published = collect(app(MqttService::class)->published)->firstWhere('topic', "remoment/playercmd/{$id}");
        $command = json_decode($published['message'], true);
        $this->assertSame('play', $command['op']);
        $this->assertSame($tracks[0]->id, $command['track']['id']);
        $this->assertSame('/stream/'.$tracks[0]->id, $command['track']['url']);
        $this->assertSame([$tracks[1]->id, $tracks[2]->id], PlayerQueue::for($id)->upNext(10));

        $tracks[0]->album->update(['images' => [['url' => 'https://img.test/cover.jpg']]]);
        $this->assertSame('https://img.test/cover.jpg', $device->driver->getUpNext(5)[0]->image);
    }

    public function test_track_end_advances_the_queue_and_polling_returns_the_commands(): void
    {
        $registered = $this->register();
        $id = $registered['device_id'];
        $tracks = collect(['One', 'Two'])->map(fn ($n) => $this->ownTrack($n));
        Device::find($id)->driver->playLibraryTracks($tracks);

        $this->putJson("/api/players/{$registered['player_token']}/state", ['state' => 'playing', 'ended' => true])->assertOk();

        $commands = $this->getJson("/api/players/{$registered['player_token']}/commands?since=1")->json('commands');
        $this->assertSame('play', $commands[0]['op']);
        $this->assertSame($tracks[1]->id, $commands[0]['track']['id']);

        $this->putJson("/api/players/{$registered['player_token']}/state", ['state' => 'playing', 'ended' => true]);
        $last = collect($this->getJson("/api/players/{$registered['player_token']}/commands?since=0")->json('commands'))->last();
        $this->assertSame('stop', $last['op']);
    }

    public function test_state_report_marks_the_player_online_and_fires_now_playing(): void
    {
        $registered = $this->register();
        $id = $registered['device_id'];
        $track = $this->ownTrack('One');
        DeviceCache::forgetListener($id);
        Event::fake([NowPlayingUpdated::class]);

        $this->putJson("/api/players/{$registered['player_token']}/state", ['state' => 'playing', 'track_id' => $track->id, 'position' => 3])->assertOk();

        $this->assertTrue(DeviceCache::isListenerRunning($id));
        $this->assertSame(State::Playing, DeviceCache::getState($id));
        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->deviceId === (string) $id);
    }

    public function test_unknown_token_is_404(): void
    {
        $this->putJson('/api/players/nope/state', ['state' => 'playing'])->assertNotFound();
    }

    public function test_only_own_files_in_a_known_format_are_playable(): void
    {
        $device = Device::find($this->register(['formats' => ['audio/mpeg']])['device_id']);
        $own = $this->ownTrack('Own');
        $foreign = Track::create(['external_id' => 'z', 'name' => 'Foreign', 'source' => 'dlna']);

        $this->assertTrue(LibraryPlayback::trackPlayable($own, $device));
        $this->assertFalse(LibraryPlayback::trackPlayable($foreign, $device));
        $this->assertSame(['audio/mpeg'], PlayerDevice::formats($device));
    }

    public function test_player_page_renders(): void
    {
        $this->get('/player')->assertOk()->assertSee('js/player.js', false);
    }

    public function test_the_bar_links_to_the_player_when_a_pinned_player_is_offline(): void
    {
        $id = $this->register()['device_id'];
        DeviceCache::forgetListener($id);

        \Livewire\Livewire::withCookie(\App\Support\SelectedDevice::COOKIE, (string) $id)
            ->test(\App\Livewire\PlayerBar::class)
            ->assertSee('open the player');
    }

    public function test_another_browser_can_adopt_an_existing_player_with_its_own_token(): void
    {
        $first = $this->register();

        $list = $this->getJson('/api/players')->assertOk()->json('data');
        $this->assertSame([$first['device_id']], array_column($list, 'id'));

        $second = $this->register(['adopt_device_id' => $first['device_id'], 'name' => 'ignored']);

        $this->assertSame($first['device_id'], $second['device_id']);
        $this->assertNotSame($first['player_token'], $second['player_token']);
        $this->assertSame('Kitchen browser', $second['name']);
        $this->assertSame(1, Device::count());

        // Both tokens work.
        $this->putJson("/api/players/{$second['player_token']}/state", ['state' => 'paused'])->assertOk();
        $this->putJson("/api/players/{$first['player_token']}/state", ['state' => 'paused'])->assertOk();
    }

    public function test_adopting_a_device_that_is_no_player_makes_a_new_player(): void
    {
        $speaker = Device::factory()->create();

        $registered = $this->register(['adopt_device_id' => $speaker->id]);

        $this->assertNotSame($speaker->id, $registered['device_id']);
    }
}
