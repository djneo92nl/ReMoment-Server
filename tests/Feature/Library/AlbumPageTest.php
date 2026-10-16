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
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The redesigned /albums/{id}: hero, waveform strip, source switch, tracklist, about tabs (docs/frontend/library.md). */
class AlbumPageTest extends TestCase
{
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

    public function test_the_title_is_shown_once_and_the_tabs_are_left_out_without_content(): void
    {
        $album = $this->album();
        $this->track($album, 'Mysterons');

        $html = $this->get(route('albums.show', $album))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('Dummy Record</h1>', $html);
        $this->assertStringNotContainsString('About this album', $html);
        $this->assertStringContainsString('tint-hero', $html);
    }

    public function test_tabs_exist_only_for_what_the_album_has(): void
    {
        $album = $this->album();
        $this->track($album, 'Mysterons', peaks: [10, 50, 90]);
        $album->metadata()->create(['key' => 'wikipedia_extract', 'value' => 'A trip-hop record.', 'type' => 'string', 'source' => 'wikipedia']);

        $this->get(route('albums.show', $album))->assertOk()
            ->assertSee('About this album')->assertSee('data-test="tab-about"', false)->assertSee('A trip-hop record.')
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

    public function test_the_waveform_strip_has_one_segment_per_track_as_wide_as_it_is_long(): void
    {
        $album = $this->album();
        $short = $this->track($album, 'Short', 100, [10, 80, 40], 1);
        $long = $this->track($album, 'Long', 300, null, 2);

        $html = $this->get(route('albums.show', $album))->assertOk()->getContent();

        $this->assertStringContainsString('data-strip-links', $html);
        $this->assertStringContainsString('flex:100 1 0', $html);
        $this->assertStringContainsString('flex:300 1 0', $html);
        $this->assertStringContainsString('href="#track-'.$short->id.'"', $html);
        $this->assertStringContainsString('id="track-'.$long->id.'"', $html);
        $this->assertStringContainsString('stroke-dasharray', $html, 'a track without a waveform is a dotted line');
    }

    public function test_the_strip_is_left_out_when_no_track_has_a_waveform(): void
    {
        $album = $this->album();
        $this->track($album, 'Mysterons');

        $this->get(route('albums.show', $album))->assertOk()->assertDontSee('data-strip-links', false);
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
}
