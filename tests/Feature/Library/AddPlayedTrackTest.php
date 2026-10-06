<?php

namespace Tests\Feature\Library;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying as NowPlayingData;
use App\Domain\Media\TrackData;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Livewire\Nowplaying;
use App\Livewire\PlayHistory;
use App\Models\Device;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use App\Services\SpotifyTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

class AddPlayedTrackTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        $device = Device::create([
            'ip_address' => '10.0.0.10', 'device_name' => 'Living Room', 'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker', 'device_driver' => FakePlayerDriver::class, 'device_driver_name' => 'Fake',
        ]);
        DeviceCache::updateState($device->id, State::Playing);

        return $device;
    }

    private function playing(Device $device): void
    {
        $artist = new ArtistData(name: 'Nobody');
        (new DeviceCache)->updateNowPlaying($device->id, new NowPlayingData(
            track: new TrackData(id: 'spotify:track:abc', name: 'Stranger', source: 'spotify', artist: $artist, duration: 200),
            album: new AlbumData(name: 'Elsewhere', artist: $artist),
            state: 'playing', position: 5, type: 'music',
        ));
    }

    public function test_now_playing_offers_to_add_a_track_the_library_lacks_and_adds_it(): void
    {
        Queue::fake();
        $device = $this->device();
        $this->playing($device);
        $play = Play::create(['device_id' => $device->id, 'track_name' => 'Stranger', 'artist_name' => 'Nobody', 'source_type' => 'spotify', 'played_at' => now()]);

        Livewire::test(Nowplaying::class, ['device' => $device])
            ->assertSee('Add to library')
            ->call('addToLibrary')
            ->assertDontSee('Add to library')
            ->assertSee('Added to the library');

        $track = Track::where('name', 'Stranger')->sole();
        $this->assertSame('spotify:track:abc', $track->external_id);
        $this->assertSame('Elsewhere', $track->album->name);
        $this->assertSame($track->id, $play->fresh()->track_id);
    }

    public function test_now_playing_has_no_button_for_a_track_already_in_the_library(): void
    {
        $device = $this->device();
        $this->playing($device);
        $artist = Artist::create(['name' => 'Nobody', 'source' => 'dlna']);
        Track::create(['artist_id' => $artist->id, 'name' => 'Stranger', 'external_id' => '1:1', 'source' => 'dlna', 'duration' => 200]);

        Livewire::test(Nowplaying::class, ['device' => $device])->assertDontSee('Add to library');
    }

    public function test_history_matches_a_text_play_to_a_library_track(): void
    {
        $device = $this->device();
        $artist = Artist::create(['name' => 'Nobody', 'source' => 'dlna']);
        $track = Track::create(['artist_id' => $artist->id, 'name' => 'Stranger (Live)', 'external_id' => '1:1', 'source' => 'dlna', 'duration' => 200]);
        $a = Play::create(['device_id' => $device->id, 'track_name' => 'Stranger', 'artist_name' => 'Nobody', 'source_type' => 'radio', 'played_at' => now()]);
        $b = Play::create(['device_id' => $device->id, 'track_name' => 'Stranger', 'artist_name' => 'Nobody', 'source_type' => 'radio', 'played_at' => now()->subHour()]);

        $this->mock(SpotifyTokenService::class, fn ($m) => $m->shouldReceive('isConnected')->andReturn(false));

        Livewire::test(PlayHistory::class)
            ->assertSee('Match')
            ->call('openMatch', $a->id)
            ->assertSet('matchName', 'Stranger')
            ->assertSee('Stranger (Live)')
            ->call('chooseLocal', $track->id)
            ->assertSet('matchPlayId', null);

        $this->assertSame($track->id, $a->fresh()->track_id);
        $this->assertSame($track->id, $b->fresh()->track_id);
    }

    public function test_history_can_add_a_spotify_result_and_match_to_it(): void
    {
        Queue::fake();
        $device = $this->device();
        $play = Play::create(['device_id' => $device->id, 'track_name' => 'Stranger', 'artist_name' => 'Nobody', 'source_type' => 'radio', 'played_at' => now()]);

        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('search')->andReturn(['tracks' => ['items' => [
            ['id' => 'sp1', 'name' => 'Stranger', 'artists' => [['name' => 'Nobody']], 'album' => ['name' => 'Elsewhere']],
        ]]]);
        $api->shouldReceive('getTrack')->with('sp1')->andReturn([
            'id' => 'sp1', 'name' => 'Stranger', 'artists' => [['name' => 'Nobody']], 'duration_ms' => 200000,
            'album' => ['name' => 'Elsewhere', 'images' => [], 'release_date' => '2020-01-01'],
        ]);
        $this->mock(SpotifyTokenService::class, function ($m) use ($api) {
            $m->shouldReceive('isConnected')->andReturn(true);
            $m->shouldReceive('makeApiClient')->andReturn($api);
        });

        Livewire::test(PlayHistory::class)
            ->call('openMatch', $play->id)
            ->assertSee('On Spotify')
            ->call('chooseSpotify', 'sp1', app(SpotifyLibraryImporter::class))
            ->assertSet('matchPlayId', null);

        $this->assertSame(Track::where('external_id', 'spotify:track:sp1')->value('id'), $play->fresh()->track_id);
    }
}
