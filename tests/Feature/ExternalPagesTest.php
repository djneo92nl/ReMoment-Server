<?php

namespace Tests\Feature;

use App\Jobs\ImportSpotifyAlbumById;
use App\Jobs\ImportSpotifyTrackById;
use App\Livewire\ArtistMoreOnSpotify;
use App\Livewire\ExternalAlbum;
use App\Livewire\ExternalArtist;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakesSpotify;
use Tests\TestCase;

/** Browse an external service's albums and artists in the web: open an album, see its tracks, play and add (also for artists the library lacks). */
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
        $api = Mockery::mock(SpotifyWebAPI::class);
        $this->connectSpotify($api);
        $album = ['id' => self::ALB, 'name' => 'First Album', 'release_date' => '1999-05-01', 'total_tracks' => 2, 'artists' => [['name' => 'Band']], 'images' => []];
        $api->shouldReceive('getArtist')->andReturn(['id' => self::ART, 'name' => 'Band', 'images' => []]);
        $api->shouldReceive('getArtistAlbums')->andReturn(['items' => [$album, ['id' => self::ALB2] + ['name' => 'Second Album', 'release_date' => '2005', 'artists' => [['name' => 'Band']], 'images' => []]], 'next' => null]);
        $api->shouldReceive('getAlbum')->andReturn($album);
        $api->shouldReceive('getAlbumTracks')->andReturn(['items' => [
            ['id' => self::TRK, 'name' => 'Opening Song', 'track_number' => 1, 'disc_number' => 1, 'duration_ms' => 200000, 'artists' => [['name' => 'Band']]],
            ['id' => 'track000000CD', 'name' => 'Closing Song', 'track_number' => 2, 'disc_number' => 1, 'duration_ms' => 180000, 'artists' => [['name' => 'Band']]],
        ], 'next' => null]);

        return $api;
    }

    public function test_the_album_page_lists_its_tracks_with_play_and_add(): void
    {
        $this->api();
        $artist = Artist::create(['name' => 'Band']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Other', 'source' => 'files']);
        Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => 'Opening Song', 'source' => 'spotify', 'external_id' => 'spotify:track:'.self::TRK]);

        $this->get('/external/spotify/albums/'.self::ALB)->assertOk()->assertSee('First Album')->assertSee('Opening Song')->assertSee('Closing Song')->assertSee('Add to library');

        Livewire::test(ExternalAlbum::class, ['albumId' => self::ALB])
            ->assertSee('Band')->assertSee('2 tracks')
            ->call('playExternal', 'album', self::ALB)->assertSee('Pick a player in the bar below first.')
            ->call('addTrack', 'track000000CD')->assertSee('Adding')
            ->call('addAlbum')->assertSee('Adding');

        Queue::assertPushed(ImportSpotifyTrackById::class, fn ($job) => $job->spotifyTrackId === 'track000000CD');
        Queue::assertPushed(ImportSpotifyAlbumById::class, fn ($job) => $job->spotifyAlbumId === self::ALB);
    }

    public function test_the_artist_page_works_for_an_artist_the_library_lacks(): void
    {
        $this->api();

        $this->get('/external/spotify/artists/'.self::ART)->assertOk()->assertSee('Band')->assertSee('First Album')->assertSee('Second Album')
            ->assertSee(route('external.album', ['provider' => 'spotify', 'id' => self::ALB]), false);

        Livewire::test(ExternalArtist::class, ['artistId' => self::ART])
            ->call('addAlbum', self::ALB)->assertSee('Adding')
            ->call('addAll')->assertSee('Adding 2 albums to the library.');
        Queue::assertPushed(ImportSpotifyAlbumById::class, fn ($job) => $job->spotifyAlbumId === self::ALB2);
    }

    public function test_it_says_so_when_the_service_is_not_connected_or_unknown(): void
    {
        $this->disconnectSpotify();
        $this->get('/external/spotify/albums/'.self::ALB)->assertOk()->assertSee('Spotify is not connected');
        $this->get('/external/nope/albums/'.self::ALB)->assertOk()->assertSee('Nope is not connected');
        $this->get('/external/spotify/albums/short')->assertNotFound();
    }

    public function test_the_more_on_spotify_cards_open_the_album_and_can_play_it(): void
    {
        $this->api();
        $artist = Artist::create(['name' => 'Band']);
        \App\Domain\Library\Enrichment::save($artist, 'spotify_id', self::ART, 'string', \App\Domain\Library\Enrichment::SPOTIFY);

        Livewire::test(ArtistMoreOnSpotify::class, ['artist' => $artist])
            ->call('load')
            ->assertSee(route('external.album', ['provider' => 'spotify', 'id' => self::ALB]), false)
            ->assertSee('Browse all on Spotify')->assertSee(route('external.artist', ['provider' => 'spotify', 'id' => self::ART]), false)
            ->call('play', self::ALB)->assertSee('Pick a player in the bar below first.');
    }
}
