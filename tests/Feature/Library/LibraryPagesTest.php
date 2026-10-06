<?php

namespace Tests\Feature\Library;

use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set(\App\Domain\Library\LeadingSource::SETTING, 'all');
    }

    private function release(string $artist, string $album, bool $favorite = false): Album
    {
        $a = Artist::firstOrCreate(['name' => $artist], ['source' => 'dlna', 'favorited_at' => $favorite ? now() : null]);
        $al = Album::create(['artist_id' => $a->id, 'name' => $album, 'source' => 'dlna', 'favorited_at' => $favorite ? now() : null]);
        Track::create(['artist_id' => $a->id, 'album_id' => $al->id, 'name' => "{$album} song", 'external_id' => "x:{$album}", 'source' => 'dlna', 'duration' => 100]);

        return $al;
    }

    public function test_artists_are_sorted_ignoring_the_and_filtered_by_letter(): void
    {
        $this->release('The Beatles', 'Help!');
        $this->release('Coldplay', 'Parachutes');
        $this->release('ABBA', 'Arrival');
        $this->release('2Pac', 'All Eyez');

        $this->get('/artists')
            ->assertOk()
            ->assertSeeInOrder(['2Pac', 'ABBA', 'The Beatles', 'Coldplay']);

        $this->get('/artists?letter=B')->assertSee('The Beatles')->assertDontSee('Coldplay')->assertDontSee('ABBA');
        $this->get('/artists?letter=%23')->assertSee('2Pac')->assertDontSee('Coldplay');
    }

    public function test_artists_can_be_searched_and_limited_to_favorites(): void
    {
        $this->release('Coldplay', 'Parachutes');
        $this->release('Muse', 'Absolution', favorite: true);

        $this->get('/artists?q=cold')->assertSee('Coldplay')->assertDontSee('Muse');
        $this->get('/artists?fav=1')->assertSee('Muse')->assertDontSee('Coldplay');
    }

    public function test_albums_can_be_searched_by_album_or_artist_and_limited_to_favorites(): void
    {
        $this->release('Coldplay', 'Parachutes');
        $this->release('Muse', 'Absolution', favorite: true);

        $this->get('/albums?q=parach')->assertSee('Parachutes')->assertDontSee('Absolution');
        $this->get('/albums?q=muse')->assertSee('Absolution')->assertDontSee('Parachutes');
        $this->get('/albums?fav=1')->assertSee('Absolution')->assertDontSee('Parachutes');
    }

    public function test_library_page_shows_recent_albums(): void
    {
        $this->release('Coldplay', 'Parachutes');

        $this->get('/library')->assertOk()->assertSee('Parachutes');
    }
}
