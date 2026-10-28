<?php

namespace Tests\Feature\Library;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying as NowPlayingData;
use App\Domain\Media\TrackData;
use App\Livewire\DeviceCard;
use App\Livewire\PlayHistory;
use App\Models\Device;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakesSpotify;
use Tests\TestCase;

class AddPlayedTrackTest extends TestCase
{
    use FakesSpotify;
    use RefreshDatabase;

    private function device(): Device
    {
        $device = Device::factory()->create();
        DeviceCache::updateState($device->id, State::Playing);
        cache()->put("listener_running_{$device->id}", true, 60);

        return $device;
    }

    private function playing(Device $device): void
    {
        $artist = new ArtistData(name: 'Nobody');
        DeviceCache::updateNowPlaying($device->id, new NowPlayingData(
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

        Livewire::test(DeviceCard::class, ['device' => $device, 'standalone' => true])
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

        Livewire::test(DeviceCard::class, ['device' => $device, 'standalone' => true])->assertDontSee('Add to library');
    }

    public function test_history_matches_a_text_play_to_a_library_track(): void
    {
        $device = $this->device();
        $artist = Artist::create(['name' => 'Nobody', 'source' => 'dlna']);
        $track = Track::create(['artist_id' => $artist->id, 'name' => 'Stranger (Live)', 'external_id' => '1:1', 'source' => 'dlna', 'duration' => 200]);
        $a = Play::create(['device_id' => $device->id, 'track_name' => 'Stranger', 'artist_name' => 'Nobody', 'source_type' => 'radio', 'played_at' => now()]);
        $b = Play::create(['device_id' => $device->id, 'track_name' => 'Stranger', 'artist_name' => 'Nobody', 'source_type' => 'radio', 'played_at' => now()->subHour()]);

        $this->disconnectSpotify();

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

    private function radioStub(Device $device): array
    {
        $artist = Artist::create(['name' => 'Nobody', 'source' => 'radio']);
        $stub = Track::create(['artist_id' => $artist->id, 'name' => 'Stranger', 'source' => 'radio', 'images' => [['url' => 'http://static.airable.io/logo.png']]]);
        $play = Play::create(['device_id' => $device->id, 'track_id' => $stub->id, 'radio_name' => 'KINK', 'source_type' => 'radio', 'played_at' => now()]);

        return [$stub, $play];
    }

    public function test_history_matches_a_radio_stub_to_a_library_track(): void
    {
        Queue::fake();
        $device = $this->device();
        [$stub, $play] = $this->radioStub($device);
        $artist = Artist::where('name', 'Nobody')->first();
        $real = Track::create(['artist_id' => $artist->id, 'name' => 'Stranger', 'external_id' => '1:1', 'source' => 'dlna', 'duration' => 200]);
        $this->disconnectSpotify();

        Livewire::test(PlayHistory::class)
            ->assertSee('Match')
            ->call('openMatch', $play->id)
            ->assertSet('matchName', 'Stranger')
            ->assertSet('matchArtist', 'Nobody')
            ->call('chooseLocal', $real->id);

        $this->assertNull(Track::find($stub->id));
        $this->assertSame($real->id, $play->fresh()->track_id);
    }
}
