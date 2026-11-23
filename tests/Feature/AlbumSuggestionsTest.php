<?php

namespace Tests\Feature;

use App\Livewire\AlbumTagEditor;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

class AlbumSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private function album(): Album
    {
        $artist = Artist::create(['name' => 'Georgie James']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Places']);
        Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Need Your Needs']);

        return $album;
    }

    public function test_lookup_lists_releases_and_the_artists_albums_and_use_fills_the_form(): void
    {
        Sleep::fake();
        Http::fake([
            'musicbrainz.org/ws/2/release?*' => Http::response(['releases' => [['id' => 'r1', 'title' => 'Places', 'date' => '2007-05-01', 'country' => 'US', 'track-count' => 11, 'score' => 100, 'artist-credit' => [['name' => 'Georgie James']]]]]),
            'musicbrainz.org/ws/2/artist?*' => Http::response(['artists' => [['id' => 'a1', 'name' => 'Georgie James', 'score' => 100]]]),
            'musicbrainz.org/ws/2/release-group?*' => Http::response(['release-groups' => [['id' => 'g1', 'title' => 'Places', 'primary-type' => 'Album', 'first-release-date' => '2007-05-01']]]),
        ]);

        Livewire::actingAs(User::factory()->create())->test(AlbumTagEditor::class, ['album' => $this->album()])
            ->call('lookup')
            ->assertSet('suggestions.releases.0.title', 'Places')
            ->assertSet('suggestions.artist.name', 'Georgie James')
            ->assertSet('suggestions.artist.albums.0.detail', 'Album · in your library')
            ->call('use', 'releases', 0)
            ->assertSet('fields.year', '2007')->assertSet('fields.album_artist', 'Georgie James')
            ->assertSet('coverUrl', 'https://coverartarchive.org/release/r1/front-500');
    }

    public function test_a_failing_lookup_is_reported_not_thrown(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response('', 503)]);

        Livewire::actingAs(User::factory()->create())->test(AlbumTagEditor::class, ['album' => $this->album()])
            ->call('lookup')->assertSet('suggestions.error', fn ($e) => str_contains((string) $e, 'MusicBrainz did not answer'));
    }

    public function test_the_cover_search_uses_the_typed_album_artist_and_a_result_can_be_picked(): void
    {
        Sleep::fake();
        Http::fake(['musicbrainz.org/ws/2/release?*' => Http::response(['releases' => [['id' => 'r9', 'title' => 'Places', 'score' => 100]]])]);

        Livewire::actingAs(User::factory()->create())->test(AlbumTagEditor::class, ['album' => $this->album()])
            ->set('fields.album_artist', 'Someone Else')
            ->call('searchCovers')
            ->assertSet('coverResults.0.cover', 'https://coverartarchive.org/release/r9/front-500')
            ->call('useCover', 0)
            ->assertSet('coverUrl', 'https://coverartarchive.org/release/r9/front-500');

        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'artist:"Someone Else"'));
    }
}
