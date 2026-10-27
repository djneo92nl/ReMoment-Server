<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;
use App\Integrations\Common\DeviceQueue;
use App\Integrations\ReMoment\PlayerDriver;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakeMovableDriver;
use Tests\Support\FakePlayerDriver;
use Tests\Support\FakesSpotify;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** POST /api/devices/{id}/move: what plays on one device continues on another. */
class MovePlaybackTest extends TestCase
{
    use FakesSpotify;
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Device $from;

    private Device $to;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        FakePlayerDriver::reset();
        config(['playback.move_wait_seconds' => 0]);

        $this->from = Device::factory()->inState(State::Playing)->create(['device_name' => 'Kitchen']);
        $this->to = Device::factory()->driver(FakeMovableDriver::class)->inState(State::Playing)->create(['device_name' => 'Bedroom']);
    }

    private function album(): Album
    {
        return Album::create(['artist_id' => Artist::create(['name' => 'Artist'])->id, 'name' => 'Album']);
    }

    private function playTrack(Device $device, Track $track, int $position = 42): void
    {
        DeviceCache::updateNowPlaying($device->id, new NowPlaying(
            track: new TrackData(name: $track->name, source: 'files', artist: new ArtistData('Artist'), duration: 200),
            album: new AlbumData(name: 'Album'),
            state: 'playing',
            position: $position,
        ));
    }

    private function calls(string $method): array
    {
        return array_values(array_filter(FakePlayerDriver::$calls, fn ($call) => $call[0] === $method));
    }

    public function test_it_moves_a_library_track_and_seeks_to_the_position(): void
    {
        $track = $this->makeLibraryTrack($this->album(), 'Song');
        $this->playTrack($this->from, $track);

        $this->postJson("/api/devices/{$this->from->id}/move", ['to_device_id' => $this->to->id])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'kind' => 'library', 'queued' => 1, 'skipped' => 0, 'position' => 42]);

        $this->assertSame([['playLibraryTracks', [$track->id]]], $this->calls('playLibraryTracks'));
        $this->assertSame(42, $this->calls('seek')[0][1]);
        $this->assertNotEmpty($this->calls('stop'), 'the source is stopped');
    }

    public function test_the_source_keeps_playing_when_asked(): void
    {
        $this->playTrack($this->from, $this->makeLibraryTrack($this->album(), 'Song'));

        $this->postJson("/api/devices/{$this->from->id}/move", ['to_device_id' => $this->to->id, 'stop_source' => false])->assertOk();

        $this->assertEmpty($this->calls('stop'));
    }

    public function test_it_moves_the_whole_server_owned_queue(): void
    {
        $album = $this->album();
        $tracks = collect(['One', 'Two', 'Three', 'NoStream'])->map(fn ($n) => $this->makeLibraryTrack($album, $n, dlna: $n !== 'NoStream'));

        $player = Device::factory()->driver(PlayerDriver::class)->inState(State::Playing)->create();
        $queue = new DeviceQueue($tracks->pluck('id')->all(), 1);
        $queue->save($player->id);
        $this->playTrack($player, $tracks[1], 10);

        $this->postJson("/api/devices/{$player->id}/move", ['to_device_id' => $this->to->id, 'stop_source' => false])
            ->assertOk()
            ->assertJson(['queued' => 2, 'skipped' => 1]);

        // From the current track on, in order.
        $this->assertSame([$tracks[1]->id, $tracks[2]->id], $this->calls('playLibraryTracks')[0][1]);
    }

    public function test_a_local_input_cannot_be_moved(): void
    {
        DeviceCache::updateNowPlaying($this->from->id, new NowPlaying(state: 'playing', source: new Source(name: 'Line-in', sourceType: 'LINEIN')));

        $this->postJson("/api/devices/{$this->from->id}/move", ['to_device_id' => $this->to->id])
            ->assertStatus(422)
            ->assertJson(['error' => 'not_movable']);

        $this->assertEmpty($this->calls('playLibraryTracks'));
        $this->assertEmpty($this->calls('stop'));
    }

    public function test_a_source_with_a_track_that_is_a_local_input_cannot_be_moved(): void
    {
        DeviceCache::updateNowPlaying($this->from->id, new NowPlaying(
            track: new TrackData(name: 'Whatever', source: 'bluetooth'),
            state: 'playing',
            source: new Source(name: 'Bluetooth', sourceType: 'BLUETOOTH'),
        ));

        $this->postJson("/api/devices/{$this->from->id}/move", ['to_device_id' => $this->to->id])->assertStatus(422)->assertJson(['error' => 'not_movable']);
    }

    public function test_nothing_playing_cannot_be_moved(): void
    {
        DeviceCache::updateState($this->from->id, State::Standby);

        $this->postJson("/api/devices/{$this->from->id}/move", ['to_device_id' => $this->to->id])->assertStatus(422)->assertJson(['error' => 'not_movable']);
    }

    public function test_a_track_the_library_does_not_have_cannot_be_moved(): void
    {
        DeviceCache::updateNowPlaying($this->from->id, new NowPlaying(track: new TrackData(name: 'Unknown', artist: new ArtistData('Nobody')), state: 'playing'));

        $this->postJson("/api/devices/{$this->from->id}/move", ['to_device_id' => $this->to->id])->assertStatus(422)->assertJson(['error' => 'not_movable']);
    }

    public function test_it_moves_radio_by_starting_the_station(): void
    {
        $station = RadioStation::create(['name' => 'Radio 1']);
        DeviceCache::updateNowPlaying($this->from->id, new NowPlaying(state: 'playing', platform: 'radio', radio: new Radio(name: 'Radio 1')));

        $this->postJson("/api/devices/{$this->from->id}/move", ['to_device_id' => $this->to->id])
            ->assertOk()
            ->assertJson(['kind' => 'radio']);

        $this->assertSame($station->id, $this->calls('playRadioStation')[0][1]);
    }

    public function test_it_hands_spotify_over_with_a_transfer(): void
    {
        Device::factory()->driver(\App\Integrations\Spotify\MusicPlayerDriver::class, 'Spotify')->inState(State::Standby)->create(['device_name' => 'Spotify']);
        DeviceMeta::create(['device_id' => $this->from->id, 'key' => 'spotify_connect_name', 'value' => 'Kitchen Speaker']);
        DeviceMeta::create(['device_id' => $this->to->id, 'key' => 'spotify_connect_name', 'value' => 'Bedroom Speaker']);
        DeviceCache::setSpotifyRoutedDevice($this->from->id);
        $this->playTrack($this->from, $this->makeLibraryTrack($this->album(), 'Song', spotifyId: 'abc'));

        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('getMyDevices')->andReturn(['devices' => [['id' => 'conn-b', 'name' => 'Bedroom Speaker']]]);
        $api->shouldReceive('changeMyDevice')->once()->with(['device_ids' => ['conn-b'], 'play' => true])->andReturn(true);
        $this->connectSpotify($api);

        $this->postJson("/api/devices/{$this->from->id}/move", ['to_device_id' => $this->to->id])
            ->assertOk()
            ->assertJson(['kind' => 'spotify']);

        $this->assertEmpty($this->calls('stop'), 'Spotify takes playback away itself');
    }

    public function test_spotify_cannot_move_to_a_speaker_without_a_connect_name(): void
    {
        Device::factory()->driver(\App\Integrations\Spotify\MusicPlayerDriver::class, 'Spotify')->inState(State::Standby)->create();
        DeviceCache::setSpotifyRoutedDevice($this->from->id);
        $this->playTrack($this->from, $this->makeLibraryTrack($this->album(), 'Song', spotifyId: 'abc'));
        $this->connectSpotify();

        $this->postJson("/api/devices/{$this->from->id}/move", ['to_device_id' => $this->to->id])
            ->assertStatus(422)
            ->assertJson(['error' => 'not_playable']);
    }

    public function test_an_unreachable_target_is_a_503_and_the_source_keeps_playing(): void
    {
        $this->playTrack($this->from, $this->makeLibraryTrack($this->album(), 'Song'));
        DeviceCache::updateState($this->to->id, State::Unreachable);

        $this->postJson("/api/devices/{$this->from->id}/move", ['to_device_id' => $this->to->id])->assertStatus(503);

        $this->assertEmpty($this->calls('stop'));
    }

    public function test_a_device_cannot_move_to_itself(): void
    {
        $this->postJson("/api/devices/{$this->from->id}/move", ['to_device_id' => $this->from->id])->assertStatus(422);
    }

    public function test_the_capability_is_listed_while_something_movable_plays(): void
    {
        $this->getJson("/api/devices/{$this->from->id}")->assertJsonMissing(['move_playback']);

        $this->playTrack($this->from, $this->makeLibraryTrack($this->album(), 'Song'));
        $this->assertContains('move_playback', $this->getJson("/api/devices/{$this->from->id}")->json('data.capabilities'));

        DeviceCache::updateNowPlaying($this->from->id, new NowPlaying(state: 'playing', source: new Source(name: 'TV', sourceType: 'HDMI')));
        $this->assertNotContains('move_playback', $this->getJson("/api/devices/{$this->from->id}")->json('data.capabilities'));
    }
}
