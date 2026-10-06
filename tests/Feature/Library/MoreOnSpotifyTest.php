<?php

namespace Tests\Feature\Library;

use App\Jobs\ImportSpotifyAlbumById;
use App\Livewire\AlbumMoreOnSpotify;
use App\Livewire\ArtistMoreOnSpotify;
use App\Livewire\GlobalSearch;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Services\SpotifyTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\TestCase;

class MoreOnSpotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function connect(SpotifyWebAPI $api): void
    {
        $this->mock(SpotifyTokenService::class, function ($mock) use ($api) {
            $mock->shouldReceive('makeApiClient')->andReturn($api);
            $mock->shouldReceive('isConnected')->andReturn(true);
        });
    }

    private function localAlbum(): Album
    {
        $artist = Artist::create(['name' => 'Beatles', 'source' => 'dlna']);
        Metadata::create(['metadatable_type' => Artist::class, 'metadatable_id' => $artist->id, 'key' => 'spotify_id', 'value' => 'sp-beatles', 'type' => 'string', 'source' => 'spotify']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Help!', 'source' => 'dlna']);
        Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Help!', 'external_id' => '1:1', 'source' => 'dlna', 'duration' => 138]);

        return $album;
    }

    public function test_artist_page_lists_only_spotify_albums_the_library_lacks_and_adds_them(): void
    {
        Queue::fake();
        $album = $this->localAlbum();

        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('getArtistAlbums')->once()->andReturn(['items' => [
            ['id' => 'a1', 'name' => 'Help! (Remastered 2009)', 'release_date' => '1965-08-06', 'artists' => [['name' => 'The Beatles']], 'images' => []],
            ['id' => 'a2', 'name' => 'Abbey Road', 'release_date' => '1969-09-26', 'artists' => [['name' => 'The Beatles']], 'images' => []],
        ], 'next' => null]);
        $this->connect($api);

        Livewire::test(ArtistMoreOnSpotify::class, ['artist' => $album->artist])
            ->call('load')
            ->assertSee('Abbey Road')
            ->assertDontSee('Help!')
            ->call('add', 'a2')
            ->assertSee('Adding');

        Queue::assertPushed(ImportSpotifyAlbumById::class, fn ($job) => $job->spotifyAlbumId === 'a2');
    }

    public function test_album_page_offers_the_tracks_the_album_lacks(): void
    {
        $album = $this->localAlbum();
        Metadata::create(['metadatable_type' => Album::class, 'metadatable_id' => $album->id, 'key' => 'spotify_album_uri', 'value' => 'spotify:album:sa1', 'type' => 'string', 'source' => 'spotify']);

        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('getAlbumTracks')->once()->andReturn(['items' => [
            ['id' => 't1', 'name' => 'Help!', 'track_number' => 1, 'duration_ms' => 138000],
            ['id' => 't2', 'name' => 'Bonus Demo', 'track_number' => 2, 'duration_ms' => 120000],
        ], 'next' => null]);
        $this->connect($api);

        Livewire::test(AlbumMoreOnSpotify::class, ['album' => $album])
            ->call('load')
            ->assertSee('More on Spotify')
            ->assertSee('Bonus Demo')
            ->assertSee('Add all 1 to album');
    }

    public function test_global_search_shows_spotify_results_marking_what_is_in_the_library(): void
    {
        Queue::fake();
        $this->localAlbum();

        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('search')->once()->andReturn([
            'artists' => ['items' => [['id' => 'sp-beatles', 'name' => 'The Beatles', 'images' => []]]],
            'albums' => ['items' => [
                ['id' => 'a1', 'name' => 'Help!', 'release_date' => '1965', 'artists' => [['name' => 'The Beatles']], 'images' => []],
                ['id' => 'a2', 'name' => 'Abbey Road', 'release_date' => '1969', 'artists' => [['name' => 'The Beatles']], 'images' => []],
            ]],
        ]);
        $this->connect($api);

        Livewire::test(GlobalSearch::class)
            ->set('query', 'beatles')
            ->assertSee('From Spotify')
            ->assertSee('Abbey Road')
            ->assertSee('In library')
            ->call('addAlbum', 'a2')
            ->assertSee('Adding');

        Queue::assertPushed(ImportSpotifyAlbumById::class, fn ($job) => $job->spotifyAlbumId === 'a2');
    }

    public function test_global_search_without_spotify_is_unchanged(): void
    {
        $this->mock(SpotifyTokenService::class, fn ($m) => $m->shouldReceive('isConnected')->andReturn(false));
        $this->localAlbum();

        Livewire::test(GlobalSearch::class)->set('query', 'beatles')->assertSee('Beatles')->assertDontSee('From Spotify');
    }
}
