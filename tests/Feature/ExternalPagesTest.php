<?php

namespace Tests\Feature;

use App\Livewire\ExternalAlbum;
use App\Livewire\ExternalArtist;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Media\WantedTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakesSpotify;
use Tests\TestCase;

/**
 * Streaming as an extension of the local library: a Spotify page for any artist or album, everything the service has, playable,
 * tracks wantable (wishlist), never favorited, nothing imported.
 */
class ExternalPagesTest extends TestCase
{
    use FakesSpotify, RefreshDatabase;

    private const ART = 'artist00000AB';

    private const ALB = 'album000000AB';

    private const ALB2 = 'album000000CD';

    private const TRK = 'track000000AB';

    private function api(): \Mockery\MockInterface
    {
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        $api = Mockery::mock(SpotifyWebAPI::class);
        $this->connectSpotify($api);
        $album = ['id' => self::ALB, 'name' => 'First Album', 'release_date' => '1999-05-01', 'total_tracks' => 2, 'artists' => [['name' => 'Band']], 'images' => []];
        $api->shouldReceive('getArtist')->andReturn(['id' => self::ART, 'name' => 'Band', 'images' => []]);
        $api->shouldReceive('getArtistAlbums')->andReturn(['items' => [$album, ['id' => self::ALB2, 'name' => 'Second Album', 'release_date' => '2005', 'artists' => [['name' => 'Band']], 'images' => []]], 'next' => null]);
        $api->shouldReceive('getAlbum')->andReturn($album);
        $api->shouldReceive('getAlbumTracks')->andReturn(['items' => [
            ['id' => self::TRK, 'name' => 'Opening Song', 'track_number' => 1, 'disc_number' => 1, 'duration_ms' => 200000, 'artists' => [['name' => 'Band']]],
            ['id' => 'track000000CD', 'name' => 'Closing Song', 'track_number' => 2, 'disc_number' => 1, 'duration_ms' => 180000, 'artists' => [['name' => 'Band']]],
        ], 'next' => null]);
        // Popular tracks: no Last.fm key in tests, so Spotify's own search for the artist's tracks.
        $api->shouldReceive('search')->andReturnUsing(fn ($q, $type) => $type === 'artist'
            ? ['artists' => ['items' => [['id' => self::ART, 'name' => 'Band']]]]
            : ['tracks' => ['items' => [
                ['id' => 'track000000EF', 'name' => 'The Big Hit', 'artists' => [['id' => self::ART, 'name' => 'Band']], 'album' => ['name' => 'First Album', 'images' => []]],
                ['id' => 'track000000GH', 'name' => 'Someone Elses Song', 'artists' => [['id' => 'other0000000', 'name' => 'Other']], 'album' => ['name' => 'X', 'images' => []]],
            ]]]);

        return $api;
    }

    public function test_the_album_page_lists_its_tracks_with_play_and_want_and_no_add(): void
    {
        $this->api();
        $artist = Artist::create(['name' => 'Band']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Other', 'source' => 'files']);
        Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => 'Opening Song', 'source' => 'spotify', 'external_id' => 'spotify:track:'.self::TRK]);

        $this->get('/external/spotify/albums/'.self::ALB)->assertOk()->assertSee('First Album')->assertSee('Opening Song')->assertSee('Closing Song')
            ->assertDontSee('Add to library')->assertDontSee('Adding');

        Livewire::test(ExternalAlbum::class, ['albumId' => self::ALB])
            ->assertSee('Band')->assertSee('2 tracks')
            ->call('playExternal', 'album', self::ALB)->assertSee('Pick a player in the bar below first.')
            ->call('toggleWant', ['title' => 'Closing Song', 'artist' => 'Band', 'album' => 'First Album', 'image' => null])
            ->assertSee('On the wishlist')
            ->call('toggleWant', ['title' => 'Closing Song', 'artist' => 'Band'])
            ->assertDontSee('On the wishlist');

        $this->assertSame(0, WantedTrack::count());
        Queue::assertNothingPushed();
    }

    public function test_the_artist_page_shows_everything_the_service_has_and_the_popular_tracks(): void
    {
        $this->api();

        $this->get('/external/spotify/artists/'.self::ART)->assertOk()->assertSee('Band')->assertSee('Popular')->assertSee('The Big Hit')->assertDontSee('Someone Elses Song')
            ->assertSee('First Album')->assertSee('Second Album')->assertSee(route('external.album', ['provider' => 'spotify', 'id' => self::ALB]), false)
            ->assertDontSee('Add all albums')->assertDontSee('Add to library');

        Livewire::test(ExternalArtist::class, ['artistId' => self::ART])
            ->call('toggleWant', ['title' => 'The Big Hit', 'artist' => 'Band', 'album' => null, 'image' => null])
            ->assertSee('On the wishlist');
        $this->assertSame('streaming', WantedTrack::first()->source);
    }

    public function test_the_spotify_side_of_a_library_artist_is_the_services_view_not_the_librarys_copies(): void
    {
        $this->api();
        $artist = Artist::create(['name' => 'Band', 'source' => 'files']);
        \App\Domain\Library\Enrichment::save($artist, 'spotify_id', self::ART, 'string', \App\Domain\Library\Enrichment::SPOTIFY);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Only Local Album', 'source' => 'files']);
        Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => 'Local Song', 'source' => 'files', 'external_id' => 'local:file:1']);

        $this->get("/artists/{$artist->id}")->assertOk()->assertSee('Only Local Album')->assertDontSee('Second Album');

        $this->get("/artists/{$artist->id}?source=spotify")->assertOk()
            ->assertSee('Second Album')->assertSee('First Album')->assertSee('The Big Hit')->assertSee('Everything Spotify has')
            ->assertDontSee('Only Local Album')->assertDontSee('Local Song');
    }

    public function test_the_spotify_id_of_an_artist_is_found_by_name_and_remembered(): void
    {
        $this->api();
        $artist = Artist::create(['name' => 'Band', 'source' => 'files']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Local', 'source' => 'files']);
        Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => 'T', 'source' => 'files', 'external_id' => 'local:file:2']);
        $this->get("/artists/{$artist->id}?source=spotify")->assertOk()->assertSee('Second Album');

        $this->assertSame(self::ART, $artist->metadata()->where('key', 'spotify_id')->value('value'));
    }

    public function test_it_says_so_when_the_service_is_not_connected_or_unknown(): void
    {
        $this->disconnectSpotify();
        $this->get('/external/spotify/albums/'.self::ALB)->assertOk()->assertSee('Spotify is not connected');
        $this->get('/external/nope/albums/'.self::ALB)->assertOk()->assertSee('Nope is not connected');
        $this->get('/external/spotify/albums/short')->assertNotFound();
    }

    public function test_local_tracks_are_marked_on_spotify_even_without_a_spotify_id(): void
    {
        $this->api();
        $artist = Artist::create(['name' => 'Band', 'source' => 'files']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'First Album', 'source' => 'files']);
        Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => 'The Big Hit', 'source' => 'files', 'external_id' => 'local:file:9']);
        Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => 'Opening Song', 'source' => 'dlna', 'external_id' => '1:5']);

        $popular = $this->get('/external/spotify/artists/'.self::ART)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/The Big Hit.{0,300}In your library/s', $popular);

        $page = $this->get('/external/spotify/albums/'.self::ALB)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Opening Song.{0,400}In library/s', $page);
        $this->assertStringNotContainsString('Closing Song</p>'."\n".'                        <span class="flex-none text-xs text-gray-400 dark:text-gray-600">In library', $page);
    }

    public function test_the_album_source_switch_does_not_count_tracks_for_spotify(): void
    {
        $this->api();
        $artist = Artist::create(['name' => 'Band', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'dlna']);
        Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => 'T', 'source' => 'dlna', 'external_id' => '1:1']);

        $html = $this->get("/albums/{$album->id}")->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/DLNA\s*<span[^>]*>\s*1\s*<\/span>/', $html);
        $this->assertDoesNotMatchRegularExpression('/Spotify\s*<span[^>]*>\s*\d+\s*<\/span>/', $html);
    }
}
