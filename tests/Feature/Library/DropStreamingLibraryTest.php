<?php

namespace Tests\Feature\Library;

use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

class DropStreamingLibraryTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private function album(string $artistName, string $albumName, string $source): Album
    {
        $artist = Artist::create(['name' => $artistName, 'source' => $source]);

        return Album::create(['artist_id' => $artist->id, 'name' => $albumName, 'source' => $source]);
    }

    public function test_streaming_only_tracks_go_and_their_plays_become_text_plays(): void
    {
        $device = Device::factory()->create();
        $dlna = $this->album('Band', 'Streamed', 'dlna');
        $track = $this->makeLibraryTrack($dlna, 'From the NAS', streamSource: 'dlna:1');
        $spotifyAlbum = $this->album('Pop', 'Cloud', 'spotify');
        $spotifyTrack = $this->makeLibraryTrack($spotifyAlbum, 'Cloud song', dlna: false, spotifyId: 'abc');
        $play = Play::create(['device_id' => $device->id, 'track_id' => $track->id, 'played_at' => now()]);

        $this->artisan('library:drop-streaming', ['--force' => true])->assertSuccessful();

        $this->assertNull(Track::find($track->id));
        $this->assertNull(Track::find($spotifyTrack->id));
        $this->assertSame(0, Album::count());
        $this->assertSame(0, Artist::count());
        $play->refresh();
        $this->assertNull($play->track_id);
        $this->assertSame('From the NAS', $play->track_name);
        $this->assertSame('Band', $play->artist_name);
    }

    public function test_a_dlna_track_that_also_has_an_own_file_stays(): void
    {
        $album = $this->album('Band', 'Both', 'dlna');
        $track = $this->makeLibraryTrack($album, 'Kept', streamSource: 'files');
        $track->update(['source' => 'dlna']);

        $this->artisan('library:drop-streaming', ['--force' => true])->assertSuccessful();

        $this->assertNotNull(Track::find($track->id));
        $this->assertSame(1, Album::count());
    }

    public function test_own_files_and_radio_stubs_stay(): void
    {
        $album = $this->album('Own', 'Files', 'files');
        $own = $this->makeLibraryTrack($album, 'Mine', streamSource: 'files');
        $radio = Track::create(['artist_id' => $album->artist_id, 'external_id' => 'radio:1', 'name' => 'Station', 'source' => 'radio']);

        $this->artisan('library:drop-streaming', ['--force' => true])->assertSuccessful();

        $this->assertNotNull(Track::find($own->id));
        $this->assertNotNull(Track::find($radio->id));
    }

    public function test_spotify_playlists_go_and_local_and_smart_playlists_stay(): void
    {
        $spotify = Playlist::create(['name' => 'Synced', 'source' => 'spotify']);
        $local = Playlist::create(['name' => 'Mine', 'source' => 'local']);
        $smart = Playlist::create(['name' => 'Smart', 'source' => 'smart']);

        $this->artisan('library:drop-streaming', ['--force' => true])->assertSuccessful();

        $this->assertNull(Playlist::find($spotify->id));
        $this->assertNotNull(Playlist::find($local->id));
        $this->assertNotNull(Playlist::find($smart->id));
    }

    public function test_albums_and_artists_without_tracks_are_swept(): void
    {
        $this->album('Empty', 'Nothing', 'spotify');
        $own = $this->album('Own', 'Files', 'files');

        $this->artisan('library:drop-streaming', ['--force' => true])->assertSuccessful();

        $this->assertSame([$own->id], Album::pluck('id')->all());
        $this->assertSame(1, Artist::count());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $album = $this->album('Band', 'Streamed', 'dlna');
        $track = $this->makeLibraryTrack($album, 'From the NAS', streamSource: 'dlna:1');
        Playlist::create(['name' => 'Synced', 'source' => 'spotify']);

        $this->artisan('library:drop-streaming', ['--dry-run' => true])
            ->expectsOutputToContain('1 streaming-only tracks and 1 Spotify playlists')
            ->assertSuccessful();

        $this->assertNotNull(Track::find($track->id));
        $this->assertSame(1, Playlist::count());
    }
}
