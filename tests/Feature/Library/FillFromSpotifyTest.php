<?php

namespace Tests\Feature\Library;

use App\Jobs\ImportSpotifyAlbumById;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Services\SpotifyTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\TestCase;

class FillFromSpotifyTest extends TestCase
{
    use RefreshDatabase;

    private function connectSpotify(SpotifyWebAPI $api): void
    {
        $this->mock(SpotifyTokenService::class, function ($mock) use ($api) {
            $mock->shouldReceive('makeApiClient')->andReturn($api);
            $mock->shouldReceive('isConnected')->andReturn(true);
        });
    }

    private function dlnaAlbum(): Album
    {
        $artist = Artist::create(['name' => 'Beatles', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Help!', 'source' => 'dlna']);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Help!', 'external_id' => '1:1', 'source' => 'dlna', 'duration' => 138]);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'dlna_url', 'value' => 'http://x/1', 'type' => 'url', 'source' => 'dlna:1']);

        return $album;
    }

    public function test_filling_a_dlna_album_adds_the_spotify_tracks_to_it(): void
    {
        Queue::fake();
        $album = $this->dlnaAlbum();

        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('search')->andReturn(['albums' => ['items' => [
            ['id' => 'alb1', 'name' => 'Help! (Remastered)', 'artists' => [['name' => 'The Beatles']]],
        ]]]);
        $api->shouldReceive('getAlbum')->with('alb1')->andReturn(['id' => 'alb1', 'uri' => 'spotify:album:alb1', 'name' => 'Help! (Remastered)', 'images' => [], 'release_date' => '1965']);
        $api->shouldReceive('getAlbumTracks')->andReturn(['total' => 2, 'items' => [
            ['id' => 't1', 'name' => 'Help!', 'duration_ms' => 138000, 'artists' => [['name' => 'The Beatles']]],
            ['id' => 't2', 'name' => 'The Night Before', 'duration_ms' => 150000, 'artists' => [['name' => 'The Beatles']]],
        ]]);
        $this->connectSpotify($api);

        $this->post(route('albums.fill', $album))->assertSessionHas('success');

        $this->assertSame(1, Album::count());
        $this->assertSame(2, $album->tracks()->count());
        $this->assertSame('spotify:album:alb1', $album->metadata()->where('key', 'spotify_album_uri')->value('value'));
    }

    public function test_filling_an_artist_queues_every_spotify_album(): void
    {
        Queue::fake();
        $album = $this->dlnaAlbum();

        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('search')->andReturn(['artists' => ['items' => [['id' => 'art1', 'name' => 'The Beatles']]]]);
        $api->shouldReceive('getArtistAlbums')->andReturn(['total' => 2, 'items' => [['id' => 'a1'], ['id' => 'a2']]]);
        $this->connectSpotify($api);

        $this->post(route('artists.fill', $album->artist))->assertSessionHas('success');

        Queue::assertPushed(ImportSpotifyAlbumById::class, 2);
    }

    public function test_album_page_offers_the_filter_once_spotify_tracks_were_added(): void
    {
        $album = $this->dlnaAlbum();
        Track::create(['artist_id' => $album->artist_id, 'album_id' => $album->id, 'name' => 'Added', 'external_id' => 'spotify:track:x', 'source' => 'spotify']);
        $this->connectSpotify(Mockery::mock(SpotifyWebAPI::class));

        $this->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee('Fill out album from Spotify')
            ->assertSee('Only my library');
    }
}
