<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LeadingSource;
use App\Models\Device;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\Setting;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Support\SelectedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\FakesSpotify;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The redesigned /albums/{id}: hero, waveform strip, source switch, tracklist, about tabs (docs/frontend/library.md). */
class AlbumPageTest extends TestCase
{
    use FakesSpotify;
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeLibraryPlaybackDriver::reset();
        Setting::set(LeadingSource::SETTING, 'all');
    }

    private function album(string $name = 'Dummy Record'): Album
    {
        $artist = Artist::firstOrCreate(['name' => 'Portishead'], ['source' => 'dlna']);

        return Album::create(['artist_id' => $artist->id, 'name' => $name, 'source' => 'dlna', 'colors' => ['#224488', '#88aacc']]);
    }

    /** A DLNA track, with a waveform when $peaks is given. */
    private function track(Album $album, string $name, int $duration = 200, ?array $peaks = null, int $number = 1): Track
    {
        $track = $this->makeLibraryTrack($album, $name);
        $track->update(['duration' => $duration]);
        $track->metadata()->create(['key' => 'track_number', 'value' => (string) $number, 'type' => 'string', 'source' => 'dlna:1']);

        if ($peaks !== null) {
            $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => sys_get_temp_dir()], 'role' => 'library']);
            $file = File::create(['storage_location_id' => $location->id, 'path' => "{$track->id}.flac", 'hash' => "h{$track->id}", 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT, 'extension' => 'flac']);
            TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
            FileAnalysis::create(['file_id' => $file->id, 'waveform' => $peaks]);
        }

        return $track;
    }

    private function spotifyTrack(Album $album, string $name, string $id, int $number = 9): Track
    {
        $track = $this->makeLibraryTrack($album, $name, dlna: false, spotifyId: $id);
        $track->metadata()->create(['key' => 'track_number', 'value' => (string) $number, 'type' => 'string', 'source' => 'spotify']);

        return $track;
    }

    private function speaker(): Device
    {
        return Device::factory()->create(['device_name' => 'Kitchen Speaker', 'device_driver' => FakeLibraryPlaybackDriver::class]);
    }

    public function test_the_title_is_shown_once_and_only_the_tracks_tab_exists_without_content(): void
    {
        $album = $this->album();
        $this->track($album, 'Mysterons');

        $html = $this->get(route('albums.show', $album))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('Dummy Record</h1>', $html);
        $this->assertStringContainsString('data-test="tab-tracks"', $html);
        $this->assertStringNotContainsString('data-test="tab-sound"', $html);
        $this->assertStringContainsString('tint-hero', $html);
    }

    public function test_tabs_exist_only_for_what_the_album_has(): void
    {
        $album = $this->album();
        $this->track($album, 'Mysterons', peaks: [10, 50, 90]);
        $album->metadata()->create(['key' => 'wikipedia_extract', 'value' => 'A trip-hop record.', 'type' => 'string', 'source' => 'wikipedia']);

        $this->get(route('albums.show', $album))->assertOk()
            ->assertSee('data-test="tab-about"', false)->assertSee('A trip-hop record.')
            ->assertDontSee('data-test="tab-credits"', false)->assertDontSee('data-test="tab-stats"', false);
    }

    public function test_the_hero_names_the_pinned_player_and_plays_from_the_selected_source(): void
    {
        $album = $this->album();
        $this->track($album, 'Mysterons');
        $speaker = $this->speaker();

        $this->get(route('albums.show', $album))->assertOk()->assertSee('Choose a player')->assertDontSee('Plays on Kitchen Speaker');

        $this->app->forgetScopedInstances(); // one app serves both requests here; SelectedDevice is per request
        $this->withCookie(SelectedDevice::COOKIE, (string) $speaker->id)
            ->get(route('albums.show', $album))->assertOk()
            ->assertSee('Plays on Kitchen Speaker')
            ->assertSee("/albums/{$album->id}/play/{$speaker->id}?source=dlna", false)
            ->assertSee("/albums/{$album->id}/play/{$speaker->id}?source=dlna&amp;shuffle=1", false);
    }

    public function test_the_hero_carries_the_metadata_and_flags_high_quality_tracks(): void
    {
        $album = $this->album();
        $flac = $this->track($album, 'Lossless');
        $mp3 = $this->track($album, 'Lossy', 200, null, 2);
        $flac->metadata()->createMany([
            ['key' => 'mime_type', 'value' => 'audio/flac', 'type' => 'string', 'source' => 'dlna:1'],
            ['key' => 'bit_depth', 'value' => '24', 'type' => 'int', 'source' => 'dlna:1'],
            ['key' => 'sample_rate', 'value' => '96000', 'type' => 'int', 'source' => 'dlna:1'],
        ]);
        $mp3->metadata()->createMany([
            ['key' => 'mime_type', 'value' => 'audio/mpeg', 'type' => 'string', 'source' => 'dlna:1'],
            ['key' => 'bitrate', 'value' => '128', 'type' => 'int', 'source' => 'dlna:1'],
        ]);
        $album->metadata()->create(['key' => 'lastfm_listeners', 'value' => '1234', 'type' => 'int', 'source' => 'lastfm']);

        $this->assertSame('FLAC 24/96', Track::find($flac->id)->qualityBadge());
        $this->assertNull(Track::find($mp3->id)->qualityBadge());

        $this->get(route('albums.show', $album))->assertOk()
            ->assertSee('FLAC 24/96')->assertSee('1,234 listeners on Last.fm')->assertSee('Add to playlist')
            ->assertDontSee('data-strip-links', false);
    }

    public function test_rows_have_no_device_picker_of_their_own(): void
    {
        $album = $this->album();
        foreach (range(1, 5) as $n) {
            $this->track($album, "Song {$n}", 200, null, $n);
        }
        $this->speaker();

        $html = $this->get(route('albums.show', $album))->assertOk()->getContent();

        $this->assertStringNotContainsString('play-track-', $html);
        $this->assertSame(1, substr_count($html, "open-modal.window=\"\$event.detail == 'play-on-device'"), 'one shared picker');
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_tracks(): void
    {
        $this->speaker();
        $few = $this->album('Few');
        foreach (range(1, 3) as $n) {
            $this->track($few, "Few {$n}", 200, [10, 20, 30], $n);
        }
        $many = $this->album('Many');
        foreach (range(1, 15) as $n) {
            $this->track($many, "Many {$n}", 200, [10, 20, 30], $n);
        }
        $speaker = Device::first();

        $count = function (Album $album) use ($speaker) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->app->forgetScopedInstances();
            $this->withCookie(SelectedDevice::COOKIE, (string) $speaker->id)->get(route('albums.show', $album))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $count($few); // warms caches that only fill once
        $this->assertSame($count($few), $count($many));
    }

    public function test_the_source_switch_shows_only_for_albums_in_several_sources_and_each_lists_its_own_tracks(): void
    {
        $album = $this->album();
        $this->track($album, 'Local Song');
        $this->spotifyTrack($album, 'Streamed Song', 'abc');

        $this->get(route('albums.show', [$album, 'source' => 'dlna']))->assertOk()
            ->assertSee('data-test="source-spotify"', false)->assertSee('data-test="source-dlna"', false)
            ->assertSee('Local Song')->assertDontSee('Streamed Song');

        $this->get(route('albums.show', [$album, 'source' => 'spotify']))->assertOk()
            ->assertSee('Streamed Song')->assertDontSee('Local Song');

        // An unknown source falls back to the scope's, never an empty list.
        $this->get(route('albums.show', [$album, 'source' => 'tidal']))->assertOk()->assertSee('Local Song');

        $single = $this->album('Single');
        $this->track($single, 'Only Song');
        $this->get(route('albums.show', $single))->assertOk()
            ->assertDontSee('data-test="source-dlna"', false)->assertSee('In Local');
    }

    public function test_the_scope_picks_the_default_source(): void
    {
        $album = $this->album();
        $this->track($album, 'Local Song');
        $this->spotifyTrack($album, 'Streamed Song', 'abc');

        $this->get(route('albums.show', [$album, 'scope' => 'streaming']))->assertOk()
            ->assertSee('Streamed Song')->assertDontSee('Local Song');
    }

    public function test_adding_an_album_to_a_playlist_adds_the_selected_sources_tracks_in_order(): void
    {
        $album = $this->album();
        $second = $this->track($album, 'Second', 200, null, 2);
        $first = $this->track($album, 'First', 200, null, 1);
        $this->spotifyTrack($album, 'Streamed', 'abc');
        $playlist = Playlist::create(['name' => 'Mine', 'source' => 'local']);

        $this->post(route('playlists.tracks.add', $playlist), ['album_id' => $album->id, 'source' => 'dlna'])->assertSessionHas('success');
        $this->assertSame([$first->id, $second->id], $playlist->tracks()->orderBy('playlist_track.position')->pluck('tracks.id')->all());

        $this->post(route('playlists.tracks.add', $playlist), ['album_id' => $album->id, 'source' => 'dlna'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Already in'));
        $this->assertSame(2, $playlist->tracks()->count());

        $this->post(route('playlists.tracks.add', $playlist), ['track_id' => $first->id])->assertSessionHas('success');
        $this->assertSame(2, $playlist->tracks()->count());
    }

    public function test_only_playlists_made_here_take_tracks_and_new_playlists_start_with_them(): void
    {
        $album = $this->album();
        $track = $this->track($album, 'Song');
        $smart = Playlist::create(['name' => 'Smart', 'source' => 'smart']);
        $spotify = Playlist::create(['name' => 'Synced', 'source' => 'spotify']);

        $this->post(route('playlists.tracks.add', $smart), ['track_id' => $track->id])->assertSessionHas('error');
        $this->post(route('playlists.tracks.add', $spotify), ['track_id' => $track->id])->assertSessionHas('error');
        $this->assertSame(0, $smart->tracks()->count() + $spotify->tracks()->count());

        $this->post(route('playlists.store'), ['name' => 'Fresh', 'album_id' => $album->id, 'source' => 'dlna'])->assertSessionHas('success');
        $this->assertSame([$track->id], Playlist::where('name', 'Fresh')->first()->tracks()->pluck('tracks.id')->all());

        // The modal lists the editable playlists only.
        $this->get(route('albums.show', $album))->assertOk()->assertSee('Fresh')->assertDontSee('Synced')->assertDontSee('Smart');
    }

    public function test_favouriting_a_track_toggles_it_and_lists_it_on_the_favourites_page(): void
    {
        $album = $this->album();
        $track = $this->track($album, 'Roads');

        $this->post(route('tracks.favorite', $track))->assertRedirect();
        $this->assertNotNull($track->fresh()->favorited_at);
        $this->get(route('library.favorites'))->assertOk()->assertSee('Roads')->assertSee('Tracks');

        // A favourite is the track, not one source's copy of it.
        $this->get(route('albums.show', $album))->assertOk()->assertSee('Remove from favorites');

        $this->post(route('tracks.favorite', $track));
        $this->assertNull($track->fresh()->favorited_at);
    }

    public function test_the_spotify_side_is_the_album_as_spotify_has_it_while_connected(): void
    {
        $album = $this->album();
        $this->track($album, 'Local Song');

        // Not connected: a local-only album has no switch.
        $this->get(route('albums.show', $album))->assertOk()->assertDontSee('data-test="source-spotify"', false);

        $this->connectSpotify(\Mockery::mock(\SpotifyWebAPI\SpotifyWebAPI::class));
        $this->get(route('albums.show', $album))->assertOk()
            ->assertSee('data-test="source-spotify"', false)->assertSee('data-test="source-dlna"', false);

        // The Spotify side hosts the lazily loaded Spotify tracklist, not the library's rows.
        $this->get(route('albums.show', [$album, 'source' => 'spotify']))->assertOk()
            ->assertSee('album-more-on-spotify', false)->assertDontSee('Local Song');
    }

    public function test_play_follows_what_the_pinned_player_can_play_from_the_selected_source(): void
    {
        $album = $this->album();
        $this->track($album, 'Local Song');
        $api = \Mockery::mock(\SpotifyWebAPI\SpotifyWebAPI::class);
        $this->connectSpotify($api);
        $spotifyDevice = Device::factory()->create(['device_name' => 'Spotify Connect', 'device_driver' => \App\Integrations\Spotify\MusicPlayerDriver::class, 'device_driver_name' => 'Spotify']);
        $play = "/albums/{$album->id}/play/{$spotifyDevice->id}";

        // A Spotify device only does Spotify: nothing to play on the Local side, and rows have no play button.
        $this->app->forgetScopedInstances();
        $this->withCookie(SelectedDevice::COOKIE, (string) $spotifyDevice->id)->get(route('albums.show', [$album, 'source' => 'dlna']))->assertOk()
            ->assertDontSee($play, false)->assertSee("Spotify Connect can't play Local", false)->assertDontSee('Play Local Song on', false);

        // On the Spotify side it plays the album from Spotify.
        $this->app->forgetScopedInstances();
        $this->withCookie(SelectedDevice::COOKIE, (string) $spotifyDevice->id)->get(route('albums.show', [$album, 'source' => 'spotify']))->assertOk()
            ->assertSee($play.'?source=spotify', false)->assertSee('Plays on Spotify Connect');

        // Without a pinned player the buttons open the player panel instead.
        $this->app->forgetScopedInstances();
        $this->get(route('albums.show', [$album, 'source' => 'spotify']))->assertOk()->assertSee('open-player-panel', false);
    }

    public function test_playing_the_spotify_side_starts_the_album_on_spotify_even_without_library_tracks(): void
    {
        $album = $this->album();
        $album->metadata()->create(['key' => 'spotify_album_uri', 'value' => 'spotify:album:sa1', 'type' => 'string', 'source' => 'spotify']);
        $api = \Mockery::mock(\SpotifyWebAPI\SpotifyWebAPI::class);
        $api->shouldReceive('play')->once()->with('', ['context_uri' => 'spotify:album:sa1']);
        $api->shouldReceive('shuffle')->once();
        $this->connectSpotify($api);
        $device = Device::factory()->create(['device_name' => 'Spotify Connect', 'device_driver' => \App\Integrations\Spotify\MusicPlayerDriver::class, 'device_driver_name' => 'Spotify']);

        $this->post(route('albums.play', [$album, $device, 'source' => 'spotify']))->assertSessionHas('success');
    }
}
