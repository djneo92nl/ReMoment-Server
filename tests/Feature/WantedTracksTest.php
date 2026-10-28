<?php

namespace Tests\Feature;

use App\Domain\Catalogue\WantedTracks;
use App\Livewire\PlayHistory;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Client;
use App\Models\Device;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Media\WantedTrack;
use App\Models\Play;
use App\Models\StorageLocation;
use App\Services\Local\LocalLibraryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File as Fs;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Songs from the play history or radio: the heart favorites them (adding them to the library), the star wishes for them. */
class WantedTracksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Putting a song in the library queues its enrichment: never let a test reach MusicBrainz, Last.fm or Spotify.
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function textPlay(array $extra = []): Play
    {
        return Play::create($extra + [
            'device_id' => Device::factory()->create()->id, 'track_name' => 'Radio Song', 'artist_name' => 'Station Band', 'album_name' => 'Some LP',
            'source_type' => 'radio', 'radio_name' => 'Radio X', 'played_at' => now(),
        ]);
    }

    public function test_the_heart_puts_a_text_only_play_in_the_library_and_favorites_it(): void
    {
        $play = $this->textPlay();
        $this->assertNull($play->track_id);

        Livewire::test(PlayHistory::class)->assertSee('Radio Song')->call('favorite', $play->id);

        $track = Track::where('name', 'Radio Song')->first();
        $this->assertNotNull($track);
        $this->assertSame($track->id, $play->fresh()->track_id, 'the play now points at the library track');
        $this->assertNotNull($track->fresh()->favorited_at);

        Livewire::test(PlayHistory::class)->call('favorite', $play->id);
        $this->assertNull($track->fresh()->favorited_at, 'a second tap removes it');
        $this->assertSame(1, Track::where('name', 'Radio Song')->count());
    }

    public function test_the_heart_replaces_a_radio_stub_by_the_real_track(): void
    {
        $artist = Artist::create(['name' => 'Station Band', 'source' => 'radio']);
        $stub = Track::create(['artist_id' => $artist->id, 'name' => 'Radio Song', 'source' => 'radio', 'external_id' => 'radio:1']);
        $play = $this->textPlay(['track_id' => $stub->id, 'track_name' => null, 'artist_name' => null]);

        Livewire::test(PlayHistory::class)->call('favorite', $play->id);

        $real = $play->fresh()->track;
        $this->assertFalse($real->isRadioStub());
        $this->assertNotNull($real->favorited_at);
    }

    public function test_the_star_wishes_and_unwishes_from_a_history_row(): void
    {
        $play = $this->textPlay();

        Livewire::test(PlayHistory::class)->call('want', $play->id);
        $song = WantedTrack::first();
        $this->assertSame(['Station Band', 'Radio Song', 'Some LP', 'radio'], [$song->artist, $song->title, $song->album, $song->source]);
        $this->assertNull(Track::where('name', 'Radio Song')->first(), 'wishing does not add the song to the library');

        Livewire::test(PlayHistory::class)->assertSee('On the wishlist')->call('want', $play->id);
        $this->assertSame(0, WantedTrack::count());
    }

    public function test_one_wish_per_song_whatever_the_spelling(): void
    {
        WantedTracks::wish('The Band', 'Hit (Remastered 2011)');
        WantedTracks::wish('Band', 'Hit', 'LP');

        $this->assertSame(1, WantedTrack::count());
        $this->assertSame('LP', WantedTrack::first()->album, 'a missing album is filled in');
    }

    public function test_the_wish_closes_when_an_own_file_of_the_song_is_indexed(): void
    {
        $base = sys_get_temp_dir().'/remoment-wanted-'.uniqid();
        Fs::makeDirectory($base.'/library', 0777, true);
        $data = str_repeat(chr(0x80), 8000);
        file_put_contents($base.'/library/Radio Song.wav', 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8).'data'.pack('V', strlen($data)).$data);
        $song = WantedTracks::wish('Unknown Artist', 'Radio Song');
        $this->assertNull($song->fulfilled_at);

        app(LocalLibraryScanner::class)->scan(StorageLocation::create(['name' => 'library', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => $base.'/library']]));
        Fs::deleteDirectory($base);

        $song->refresh();
        $this->assertNotNull($song->track_id);
        $this->assertNotNull($song->fulfilled_at);
        $this->assertSame([], WantedTracks::wishlist());
    }

    public function test_a_song_in_the_library_without_files_is_linked_but_not_fulfilled(): void
    {
        $artist = Artist::create(['name' => 'Band']);
        $track = Track::create(['artist_id' => $artist->id, 'name' => 'Hit', 'source' => 'spotify', 'external_id' => 'spotify:track:1']);

        $song = WantedTracks::wish('Band', 'Hit');

        $this->assertSame($track->id, $song->track_id);
        $this->assertNull($song->fulfilled_at);
    }

    public function test_api_wish_list_and_remove(): void
    {
        $created = $this->postJson('/api/library/wanted-tracks', ['artist' => 'Band', 'title' => 'Hit', 'album' => 'LP', 'source' => 'radio'])->assertCreated()->json();
        $this->postJson('/api/library/wanted-tracks', ['artist' => 'band', 'title' => 'hit'])->assertOk();
        $this->assertSame(1, WantedTrack::count());

        $wishlist = $this->getJson('/api/library/wishlist')->assertOk()->json();
        $this->assertSame(['Hit'], array_column($wishlist['songs'], 'title'));
        $this->assertSame('radio', $wishlist['songs'][0]['source']);

        $this->postJson('/api/library/wanted-tracks', ['title' => 'No artist'])->assertStatus(422);
        $this->deleteJson("/api/library/wanted-tracks/{$created['id']}")->assertOk();
        $this->assertSame([], $this->getJson('/api/library/wishlist')->json('songs'));
        $this->deleteJson('/api/library/wanted-tracks/9999')->assertNotFound();
    }

    public function test_client_devices_are_refused(): void
    {
        Client::create(['name' => 'Touch', 'type' => 'multi', 'status' => 'approved', 'api_token' => 'tok', 'registration_token' => 'reg']);

        $this->postJson('/api/library/wanted-tracks?client=tok', ['artist' => 'Band', 'title' => 'Hit'])->assertForbidden();
        $this->assertSame(0, WantedTrack::count());
    }

    public function test_mcp_and_wishlist_page_show_songs(): void
    {
        ReMomentServer::tool(Tools\Catalogue::class, ['action' => 'wish_song', 'artist' => 'Band', 'title' => 'Hit'])->assertOk();
        $this->assertSame(1, WantedTrack::count());
        ReMomentServer::tool(Tools\Catalogue::class, ['action' => 'wish_song', 'artist' => 'Band'])->assertHasErrors(['needs an artist and a title']);

        $this->get('/library/wishlist')->assertOk()->assertSee('Songs')->assertSee('Hit');
        $this->get('/library')->assertOk()->assertSee('Wishlist');

        ReMomentServer::tool(Tools\Catalogue::class, ['action' => 'unwish_song', 'id' => WantedTrack::first()->id])->assertOk();
        $this->assertSame(0, WantedTrack::count());
    }
}
