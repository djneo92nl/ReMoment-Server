<?php

namespace Tests\Feature;

use App\Domain\Device\State;
use App\Livewire\ExternalPlaylist;
use App\Livewire\ExternalPlaylists;
use App\Models\Device;
use App\Models\Media\Playlist;
use App\Models\Media\WantedTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakesSpotify;
use Tests\TestCase;

/** Playlists: Local or Streaming; a Spotify playlist is read live (browse, play, want), never imported or copied. */
class ExternalPlaylistsTest extends TestCase
{
    use FakesSpotify, RefreshDatabase;

    private const PL = 'playlist000AB';

    private function api(): \Mockery\MockInterface
    {
        Cache::flush();
        $api = Mockery::mock(SpotifyWebAPI::class);
        $this->connectSpotify($api);
        $api->shouldReceive('getMyPlaylists')->andReturn(['items' => [
            ['id' => self::PL, 'name' => 'Road Trip', 'owner' => ['display_name' => 'Me'], 'images' => [], 'tracks' => ['total' => 2]],
        ], 'next' => null]);
        $api->shouldReceive('getPlaylist')->andReturn(['id' => self::PL, 'name' => 'Road Trip', 'owner' => ['display_name' => 'Me'], 'images' => [], 'tracks' => ['total' => 2]]);

        return $api;
    }

    private function entries(array $tracks): void
    {
        Http::fake(['api.spotify.com/*' => Http::response(['items' => array_map(fn ($t) => ['track' => $t], $tracks), 'total' => count($tracks)])]);
    }

    private function track(string $id, string $name): array
    {
        return ['id' => $id, 'type' => 'track', 'name' => $name, 'artists' => [['name' => 'Band']], 'album' => ['name' => 'LP', 'images' => []]];
    }

    public function test_the_playlists_page_filters_local_and_streaming_and_leaves_imported_spotify_rows_out(): void
    {
        $this->api();
        Playlist::create(['name' => 'My Mix']);
        Playlist::create(['name' => 'Old Spotify Copy', 'source' => 'spotify', 'external_id' => 'x']);

        $this->get('/playlists')->assertOk()->assertSee('My Mix')->assertDontSee('Old Spotify Copy')->assertSee('Streaming');
        $this->get('/playlists?source=streaming')->assertOk()->assertSee('Road Trip')->assertDontSee('My Mix');
    }

    public function test_the_streaming_list_is_read_live_and_playable(): void
    {
        $this->api();

        Livewire::test(ExternalPlaylists::class)
            ->assertSee('Road Trip')->assertSee('2 tracks')->assertSee('Me')
            ->assertSee(route('external.playlist', ['provider' => 'spotify', 'id' => self::PL]), false)
            ->call('playExternal', 'playlist', self::PL)->assertSee('Pick a player in the bar below first.');

        $this->assertSame(0, Playlist::where('name', 'Road Trip')->count(), 'never imported');
    }

    public function test_the_playlist_page_shows_the_tracks_with_play_and_want_but_no_favorite(): void
    {
        $this->api();
        $this->entries([$this->track('track000000AB', 'Opening Song'), ['id' => 'ep000000AB', 'type' => 'episode', 'name' => 'A Podcast'], $this->track('track000000CD', 'Closing Song')]);

        $this->get('/external/spotify/playlists/'.self::PL)->assertOk()->assertSee('Road Trip')->assertSee('Opening Song')->assertSee('Closing Song')
            ->assertDontSee('A Podcast')->assertDontSee('favorite', false);

        Livewire::test(ExternalPlaylist::class, ['playlistId' => self::PL])
            ->call('toggleWant', ['title' => 'Opening Song', 'artist' => 'Band', 'album' => null, 'image' => null])
            ->assertSee('On the wishlist');
        $this->assertSame('streaming', WantedTrack::first()->source);
    }

    public function test_a_playlist_whose_tracks_spotify_does_not_share_still_plays(): void
    {
        $this->api();
        Http::fake(['api.spotify.com/*' => Http::response([], 403)]);

        Livewire::test(ExternalPlaylist::class, ['playlistId' => self::PL])->assertSee('only shares the tracks of playlists you own')->assertSee('Play');
    }

    public function test_the_api_lists_and_opens_playlists_and_plays_one(): void
    {
        $api = $this->api();
        $this->entries([$this->track('track000000AB', 'Opening Song')]);
        $device = Device::factory()->inState(State::Playing)->create(['device_driver' => \App\Integrations\Spotify\MusicPlayerDriver::class, 'device_driver_name' => 'Spotify']);
        $api->shouldReceive('play')->with('', ['context_uri' => 'spotify:playlist:'.self::PL])->once();
        $api->shouldReceive('shuffle')->andReturn(true);

        $this->getJson('/api/library/external/spotify/playlists')->assertOk()->assertJsonPath('data.0.title', 'Road Trip')->assertJsonPath('data.0.type', 'playlist');
        $this->getJson('/api/library/external/spotify/playlists/'.self::PL)->assertOk()->assertJsonPath('tracks.0.title', 'Opening Song');
        $this->postJson("/api/devices/{$device->id}/library/play-external", ['provider' => 'spotify', 'type' => 'playlist', 'id' => self::PL])->assertOk();
    }
}
