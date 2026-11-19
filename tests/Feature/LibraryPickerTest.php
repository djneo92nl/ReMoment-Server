<?php

namespace Tests\Feature;

use App\Domain\Library\LibraryPicks;
use App\Livewire\ExportFromLibrary;
use App\Livewire\LibraryPicker;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The reusable library picker and the picks it hands to a host (export first). */
class LibraryPickerTest extends TestCase
{
    use RefreshDatabase;

    private Artist $artist;

    private Album $album;

    /** @var list<Track> */
    private array $tracks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->artist = Artist::create(['name' => 'The Band']);
        $this->album = Album::create(['artist_id' => $this->artist->id, 'name' => 'LP']);
        foreach (['First', 'Second'] as $name) {
            $this->tracks[] = Track::create(['album_id' => $this->album->id, 'artist_id' => $this->artist->id, 'external_id' => uniqid('local:'), 'name' => $name, 'duration' => 100, 'source' => 'files']);
        }
    }

    public function test_tabs_list_rows_and_an_artist_opens_albums_then_tracks(): void
    {
        $c = Livewire::test(LibraryPicker::class)->assertSee('The Band')->assertSee('1 album')->assertDontSee('First');

        $c->call('focusItem', 'artist', $this->artist->id)->assertSee('LP')->assertDontSee('First');
        $c->call('focusItem', 'album', $this->album->id)->assertSee('First')->assertSee('Second');
        $c->call('focusItem', 'artist', $this->artist->id)->assertDontSee('First');

        $c->call('setTab', 'track')->assertSee('First')->assertSee('The Band · LP');
        $c->call('setTab', 'album')->assertSee('LP');
        $c->call('setTab', 'bogus')->assertSet('tab', 'album');
    }

    public function test_search_narrows_the_list(): void
    {
        Livewire::test(LibraryPicker::class)->call('setTab', 'track')->set('search', 'sec')->assertSee('Second')->assertDontSee('First');
    }

    public function test_add_remove_clear_and_the_basket_counts_tracks(): void
    {
        $c = Livewire::test(LibraryPicker::class)
            ->call('toggle', 'album', $this->album->id)
            ->call('toggle', 'track', $this->tracks[0]->id)
            ->assertSee('Selected (2)')->assertSeeText('3 tracks');

        $c->call('toggle', 'track', $this->tracks[0]->id)->assertSee('Selected (1)');
        $c->call('remove', 'album', $this->album->id)->assertSee('Nothing selected yet');
        $c->call('toggle', 'artist', $this->artist->id)->call('clear')->assertSet('picks', []);
    }

    public function test_unknown_types_are_dropped_and_confirm_sends_the_track_ids_once_each(): void
    {
        $c = Livewire::test(LibraryPicker::class, ['name' => 'export'])
            ->call('toggle', 'bogus', 1)->assertSet('picks', [])
            ->call('toggle', 'album', $this->album->id)->call('toggle', 'track', $this->tracks[1]->id)
            ->call('confirm');

        $c->assertDispatched('library-picked', name: 'export', trackIds: [$this->tracks[0]->id, $this->tracks[1]->id]);
    }

    public function test_playlist_pick_expands_in_its_own_order(): void
    {
        $playlist = Playlist::create(['name' => 'Mix']);
        $playlist->tracks()->attach([$this->tracks[1]->id => ['position' => 1], $this->tracks[0]->id => ['position' => 2]]);

        $this->assertSame([$this->tracks[1]->id, $this->tracks[0]->id], LibraryPicks::trackIds([['type' => 'playlist', 'id' => $playlist->id]]));
        Livewire::test(LibraryPicker::class)->call('setTab', 'playlist')->assertSee('Mix')->assertSee('2 tracks');
    }

    public function test_the_export_builder_opens_the_dialog_for_the_picked_tracks_and_is_closed_to_guests(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ExportFromLibrary::class)->assertDontSeeHtml('data-test="export-panel"')
            ->dispatch('library-picked', name: 'export', picks: [], trackIds: [$this->tracks[0]->id])
            ->assertSet('trackIds', [$this->tracks[0]->id])->assertSeeHtml('data-test="export-panel"');
    }

    public function test_a_guest_cannot_hand_tracks_to_the_export_builder(): void
    {
        Livewire::test(ExportFromLibrary::class)->dispatch('library-picked', name: 'export', picks: [], trackIds: [1])->assertForbidden();
    }

    public function test_own_files_only_hides_what_the_server_has_no_file_for(): void
    {
        $location = StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => sys_get_temp_dir()], 'role' => 'library']);
        $file = File::create(['storage_location_id' => $location->id, 'path' => 'a.mp3', 'hash' => 'h', 'size' => 1, 'mtime' => 1, 'extension' => 'mp3', 'codec' => 'mp3', 'bitrate' => 128, 'sample_rate' => 44100, 'channels' => 2, 'duration' => 100, 'status' => File::PRESENT]);
        TrackFile::create(['track_id' => $this->tracks[0]->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        $other = Artist::create(['name' => 'Streamer']);
        $streamed = Album::create(['artist_id' => $other->id, 'name' => 'Cloud']);
        Track::create(['album_id' => $streamed->id, 'artist_id' => $other->id, 'external_id' => uniqid('local:'), 'name' => 'Nowhere', 'duration' => 100, 'source' => 'files']);

        Livewire::test(LibraryPicker::class)->assertSee('Streamer');
        $c = Livewire::test(LibraryPicker::class, ['ownFilesOnly' => true])->assertSee('The Band')->assertDontSee('Streamer');
        $c->call('focusItem', 'artist', $this->artist->id)->call('focusItem', 'album', $this->album->id)->assertSee('First')->assertDontSee('Second');
        $c->call('setTab', 'track')->assertDontSee('Nowhere')->assertDontSee('Second');
        $c->call('toggle', 'album', $this->album->id)->assertSeeText('1 track');
    }
}
