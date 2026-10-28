<?php

namespace Tests\Feature;

use App\Livewire\Catalogue;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The discography panel on the artist page, the wishlist page and its shelf on /library (guest accessible like favourites). */
class CatalogueWebTest extends TestCase
{
    use RefreshDatabase;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artist = Artist::create(['name' => 'Band', 'source' => 'files']);
        $album = Album::create(['artist_id' => $this->artist->id, 'name' => 'Mine', 'source' => 'files']);
        Track::create(['album_id' => $album->id, 'artist_id' => $this->artist->id, 'name' => 'T', 'source' => 'files', 'external_id' => 'local:file:1']);
    }

    private function row(string $title, int $year, array $extra = []): CatalogueAlbum
    {
        return CatalogueAlbum::create($extra + ['artist_id' => $this->artist->id, 'release_group_mbid' => 'rg-'.md5($title), 'title' => $title, 'type' => 'album', 'first_release_year' => $year, 'seen_at' => now()]);
    }

    public function test_the_artist_page_shows_the_discography_only_when_there_is_one(): void
    {
        $this->get("/artists/{$this->artist->id}")->assertOk()->assertDontSee('Albums and EPs from MusicBrainz');

        $this->row('Missing One', 2010);
        $this->get("/artists/{$this->artist->id}")->assertOk()
            ->assertSee('Albums and EPs from MusicBrainz')->assertSee('Missing One')->assertSee('Not in your library');
    }

    public function test_wish_and_hide_from_the_panel(): void
    {
        $row = $this->row('Missing One', 2010);

        Livewire::test(Catalogue::class, ['artistId' => $this->artist->id])
            ->assertSee('Want')
            ->call('want', $row->id)
            ->assertSee('Wished');
        $this->assertNotNull($row->fresh()->wanted_at);

        Livewire::test(Catalogue::class, ['artistId' => $this->artist->id])
            ->call('want', $row->id, false)
            ->call('ignore', $row->id)
            ->assertDontSee('Missing One')
            ->set('showHidden', true)
            ->assertSee('Missing One')->assertSee('Show again')
            ->call('ignore', $row->id, false);
        $this->assertNull($row->fresh()->ignored_at);
    }

    public function test_filters_count_and_limit_long_discographies(): void
    {
        foreach (range(1, 15) as $i) {
            $this->row("Album {$i}", 1990 + $i);
        }
        $this->row('Mine', 2020);
        $this->row('Wished', 2021, ['wanted_at' => now()]);

        Livewire::test(Catalogue::class, ['artistId' => $this->artist->id])
            ->assertSee('Show all 17 entries')
            ->call('showAll')->assertDontSee('Show all')
            ->call('setFilter', 'wanted')->assertSee('Wished')->assertDontSee('Album 3')
            ->call('setFilter', 'bogus')->assertSee('Album 3');
    }

    public function test_an_owned_entry_links_to_its_album_and_offers_no_wish(): void
    {
        $this->row('Mine', 2000);
        \App\Domain\Catalogue\CatalogueLinker::linkArtist($this->artist);

        Livewire::test(Catalogue::class, ['artistId' => $this->artist->id])
            ->assertSee('Owned')->assertDontSee('Want')
            ->assertSee(route('albums.show', Album::where('name', 'Mine')->value('id')), false);
    }

    public function test_wishlist_page_and_library_shelf(): void
    {
        $this->get('/library/wishlist')->assertOk()->assertSee('Nothing wished for yet');
        $this->get('/library')->assertOk()->assertDontSee('Wishlist');

        $this->row('Missing One', 2010, ['wanted_at' => now()]);
        $this->row('Owned wish', 2011, ['wanted_at' => now(), 'fulfilled_at' => now()]);

        $this->get('/library/wishlist')->assertOk()->assertSee('Missing One')->assertDontSee('Owned wish');
        $this->get('/library')->assertOk()->assertSee('Wishlist')->assertSee('Missing One');
    }
}
