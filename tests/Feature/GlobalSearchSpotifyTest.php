<?php

namespace Tests\Feature;

use App\Livewire\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakesSpotify;
use Tests\TestCase;

/** The search box offers Spotify's artists, albums and tracks: play them on the pinned player, or add them. */
class GlobalSearchSpotifyTest extends TestCase
{
    use FakesSpotify, RefreshDatabase;

    public function test_tracks_show_with_play_and_want_and_play_needs_a_pinned_player(): void
    {
        Cache::flush();
        Queue::fake();
        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('search')->andReturn([
            'artists' => ['items' => []], 'albums' => ['items' => []],
            'tracks' => ['items' => [['id' => 'track000000AB', 'name' => 'Faraway Song', 'artists' => [['name' => 'Band']], 'album' => ['name' => 'LP', 'images' => []]]]],
        ]);
        $this->connectSpotify($api);

        Livewire::test(GlobalSearch::class)
            ->set('query', 'faraway')
            ->assertSee('Faraway Song')->assertSee('Band')
            ->call('playExternal', 'track', 'track000000AB')
            ->assertSee('Pick a player in the bar below first.')
            ->assertDontSee('Adding')
            ->call('toggleWant', ['title' => 'Faraway Song', 'artist' => 'Band', 'album' => 'LP', 'image' => null])
            ->assertSee('On the wishlist');

        $this->assertSame(['Faraway Song'], \App\Models\Media\WantedTrack::pluck('title')->all());
        Queue::assertNothingPushed();
    }
}
