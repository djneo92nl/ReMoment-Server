<?php

namespace Tests\Feature;

use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The stem mixer page (docs/frontend/stem-mixer.md): server side only; the audio runs in the browser. */
class MixerPageTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $name): Track
    {
        $artist = Artist::firstOrCreate(['name' => 'Band'], ['source' => 'dlna']);
        $album = Album::firstOrCreate(['artist_id' => $artist->id, 'name' => 'LP'], ['source' => 'dlna']);

        return Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => $name, 'duration' => 200, 'source' => 'dlna', 'external_id' => "x:{$name}"]);
    }

    public function test_the_page_is_open_to_guests_and_has_two_decks_and_a_picker(): void
    {
        $this->get('/mixer')->assertOk()
            ->assertSee('Stem mixer')
            ->assertSee('Deck A')->assertSee('Deck B')
            ->assertSee('Crossfader')->assertSee('Pick a track')
            ->assertSee('x-data="mixer(', false);
    }

    public function test_tracks_in_the_query_are_handed_to_the_decks(): void
    {
        $a = $this->track('Alpha');
        $b = $this->track('Bravo');

        $response = $this->get("/mixer?a={$a->id}&b={$b->id}")->assertOk();

        $this->assertStringContainsString('Alpha', $response->getContent());
        $this->assertStringContainsString('Bravo', $response->getContent());
        $this->assertStringContainsString('artist_name', $response->getContent());
        $this->assertStringContainsString('Band', $response->getContent());
    }

    public function test_an_unknown_track_leaves_the_deck_empty_and_a_bad_id_is_refused(): void
    {
        $this->get('/mixer?a=999999')->assertOk()->assertDontSee('999999');
        $this->get('/mixer?a=abc')->assertSessionHasErrors('a');
    }

    public function test_the_page_needs_the_full_profile(): void
    {
        config(['profile.current' => 'standard']);

        $this->get('/mixer')->assertRedirectContains('/not-in-profile');
    }
}
