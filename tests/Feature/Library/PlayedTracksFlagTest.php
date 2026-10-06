<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LibrarySettings;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingUpdated;
use App\Jobs\SendNowPlayingToLastfm;
use App\Listeners\Device\StorePlaybackHistory;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

class PlayedTracksFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function play(string $track, string $artist, ?string $album, string $cover = 'https://img.test/c.jpg'): void
    {
        $device = Device::firstOrCreate(['ip_address' => '10.0.0.1'], [
            'device_name' => 'Speaker', 'device_brand_name' => 'Test', 'device_product_type' => 'Speaker',
            'device_driver' => FakePlayerDriver::class, 'device_driver_name' => 'Fake',
        ]);
        $artistData = new ArtistData(name: $artist);
        $nowPlaying = new NowPlaying(
            track: new TrackData(id: null, name: $track, source: 'spotify', artist: $artistData, duration: 200, images: [$cover]),
            album: $album !== null ? new AlbumData(name: $album, artist: $artistData) : null,
            type: 'music',
            platform: 'media',
        );

        (new StorePlaybackHistory)->handle(new NowPlayingUpdated((string) $device->id, $nowPlaying, 'media'));
    }

    public function test_the_flag_is_off_by_default(): void
    {
        $this->assertFalse(LibrarySettings::addPlayedTracks());
    }

    public function test_an_unknown_track_is_logged_as_text_and_not_added(): void
    {
        $this->play('Stranger', 'Nobody', 'Elsewhere');

        $this->assertSame(0, Track::count() + Artist::count() + Album::count());
        $play = Play::sole();
        $this->assertNull($play->track_id);
        $this->assertSame('Stranger', $play->track_name);
        $this->assertSame('Nobody', $play->artist_name);
        $this->assertSame('Elsewhere', $play->album_name);
        $this->assertSame('https://img.test/c.jpg', $play->image_url);
        $this->assertSame(200, $play->duration);
        $this->assertTrue($play->isTrackPlay());
        Queue::assertPushed(SendNowPlayingToLastfm::class);
    }

    public function test_a_track_already_in_the_library_is_matched(): void
    {
        $artist = Artist::create(['name' => 'Beatles', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Help!', 'source' => 'dlna']);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Help!', 'external_id' => '1:1', 'source' => 'dlna', 'duration' => 200]);

        $this->play('Help!', 'The Beatles', 'Help! (Remastered)');

        $this->assertSame(1, Track::count());
        $this->assertSame(1, Album::count());
        $this->assertSame($track->id, Play::sole()->track_id);
        $this->assertNull(Play::sole()->track_name);
    }

    public function test_the_flag_on_adds_unknown_tracks_to_the_library(): void
    {
        Setting::set(LibrarySettings::ADD_PLAYED_TRACKS, '1');

        $this->play('Stranger', 'Nobody', 'Elsewhere');

        $this->assertSame(1, Track::count());
        $this->assertNotNull(Play::sole()->track_id);
        $this->assertNull(Play::sole()->track_name);
    }

    public function test_history_shows_text_plays(): void
    {
        $this->play('Stranger', 'Nobody', 'Elsewhere');

        \Livewire\Livewire::test(\App\Livewire\PlayHistory::class)
            ->assertSee('Stranger')->assertSee('Nobody')->assertSee('Elsewhere');
        \Livewire\Livewire::test(\App\Livewire\DeviceHistory::class, ['device' => Device::first()])
            ->assertSee('Stranger');
    }

    public function test_admin_can_toggle_the_flag(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());

        $this->post('/settings/library', ['leading_source' => 'auto', 'add_played_tracks' => '1']);
        $this->assertTrue(LibrarySettings::addPlayedTracks());

        $this->post('/settings/library', ['leading_source' => 'auto']);
        $this->assertFalse(LibrarySettings::addPlayedTracks());
    }
}
