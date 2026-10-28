<?php

namespace Tests\Feature;

use App\Livewire\StreamingSection;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Media\WantedTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakesSpotify;
use Tests\TestCase;

/** The Streaming scope of the library page: the account's playlists, saved albums, liked songs and recently played, read live from Spotify (nothing stored). */
class StreamingPageTest extends TestCase
{
    use FakesSpotify, RefreshDatabase;

    private function track(string $id, string $name): array
    {
        return ['id' => $id, 'type' => 'track', 'name' => $name, 'duration_ms' => 200000, 'artists' => [['name' => 'Band']], 'album' => ['name' => 'LP', 'images' => []]];
    }

    private function api(): \Mockery\MockInterface
    {
        Cache::flush();
        $api = Mockery::mock(SpotifyWebAPI::class);
        $this->connectSpotify($api);
        $api->shouldReceive('getMySavedAlbums')->andReturn(['items' => [['album' => ['id' => 'album000000AB', 'name' => 'Saved LP', 'release_date' => '2001-01-01', 'artists' => [['name' => 'Band']], 'images' => []]]]]);
        $api->shouldReceive('getMySavedTracks')->andReturn(['items' => [['track' => $this->track('track000000AB', 'Liked Song')], ['track' => $this->track('track000000CD', 'Local Song')]]]);
        $api->shouldReceive('getMyRecentTracks')->andReturn(['items' => [['track' => $this->track('track000000EF', 'Just Played')], ['track' => $this->track('track000000EF', 'Just Played')]]]);
        $api->shouldReceive('getMyPlaylists')->andReturn(['items' => [['id' => 'playlist000AB', 'name' => 'Road Trip', 'owner' => ['display_name' => 'Me'], 'images' => [], 'tracks' => ['total' => 3]]], 'next' => null]);

        return $api;
    }

    public function test_the_page_lists_the_sections_and_loads_each_on_its_own(): void
    {
        $this->api();

        $this->get('/library?scope=streaming')->assertOk()->assertSee('data-test="section-liked-tracks"', false)->assertSee('data-test="section-saved-albums"', false)->assertSee('data-test="section-recent"', false)
            ->assertDontSee('Road Trip');
        $this->get('/library?scope=local')->assertOk()->assertDontSee('Liked songs')->assertDontSee('streaming-sections', false);
        $this->get('/library/streaming')->assertRedirect('/library?scope=streaming');

        Livewire::test(StreamingSection::class, ['section' => 'saved-albums'])->assertSee('Loading from Spotify')->call('load')
            ->assertSee('Saved albums on Spotify')->assertSee('Saved LP')->assertSee('Band')->assertSee(route('external.album', ['provider' => 'spotify', 'id' => 'album000000AB']), false);
        Livewire::test(StreamingSection::class, ['section' => 'recent'])->call('load')->assertSee('Just Played');
    }

    public function test_liked_songs_mark_the_library_play_and_can_be_wanted_but_not_favorited(): void
    {
        $this->api();
        $artist = Artist::create(['name' => 'Band', 'source' => 'files']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'files']);
        Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => 'Local Song', 'source' => 'files', 'external_id' => 'local:file:3']);

        $html = Livewire::test(StreamingSection::class, ['section' => 'liked-tracks'])->call('load')
            ->assertSee('Liked Song')->assertDontSee('favorite', false)
            ->call('playExternal', 'track', 'track000000AB')->assertSee('Pick a player in the bar below first.')
            ->call('toggleWant', ['title' => 'Liked Song', 'artist' => 'Band', 'album' => null, 'image' => null])->assertSee('On the wishlist')
            ->html();

        $this->assertStringContainsString('data-test="track-rows"', $html, 'tracks are compact rows like the radio stations, not shelf cards');
        $this->assertStringNotContainsString('w-36 shrink-0', $html);
        $this->assertMatchesRegularExpression('/Local Song.{0,300}In your library/s', $html);
        $this->assertDoesNotMatchRegularExpression('/Liked Song.{0,200}In your library/s', $html);
        $this->assertSame(['Liked Song'], WantedTrack::pluck('title')->all());
    }

    public function test_a_refused_section_says_to_reconnect_and_the_others_still_load(): void
    {
        $api = Mockery::mock(SpotifyWebAPI::class);
        Cache::flush();
        $this->connectSpotify($api);
        $api->shouldReceive('getMyRecentTracks')->andThrow(new \SpotifyWebAPI\SpotifyWebAPIException('Insufficient client scope', 403));
        $api->shouldReceive('getMySavedTracks')->andReturn(['items' => [['track' => $this->track('track000000AB', 'Liked Song')]]]);

        Livewire::test(StreamingSection::class, ['section' => 'recent'])->call('load')->assertSee('Reconnect Spotify');
        Livewire::test(StreamingSection::class, ['section' => 'liked-tracks'])->call('load')->assertSee('Liked Song');
    }

    public function test_without_spotify_the_page_says_so(): void
    {
        $this->disconnectSpotify();

        $this->get('/library?scope=streaming')->assertOk()->assertSee('Spotify is not connected')->assertDontSee('Liked songs');
    }

    public function test_the_api_and_mcp_expose_the_sections(): void
    {
        $this->api();

        $this->getJson('/api/library/external/spotify/liked-tracks')->assertOk()->assertJsonPath('data.0.title', 'Liked Song')->assertJsonPath('data.0.type', 'track');
        $this->getJson('/api/library/external/spotify/saved-albums')->assertOk()->assertJsonPath('data.0.title', 'Saved LP');
        $this->getJson('/api/library/external/spotify/recent')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/library/external/spotify/nonsense')->assertNotFound();

        \App\Mcp\Servers\ReMomentServer::tool(\App\Mcp\Tools\External::class, ['action' => 'liked_tracks'])->assertOk()->assertSee('Liked Song');
    }
}
