<?php

namespace Tests\Feature;

use App\Domain\Catalogue\ArtistCatalogueSync;
use App\Domain\Catalogue\CatalogueLinker;
use App\Domain\Library\Enrichment;
use App\Domain\Library\Normalizer;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Edition matching: release-style library names link to the release-group (docs/architecture/availability-layer.md). */
class CatalogueEditionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Http::preventStrayRequests();
    }

    private function artist(?string $mbid = 'art-1'): Artist
    {
        $artist = Artist::create(['name' => 'Foo Fighters', 'source' => 'files']);
        if ($mbid) {
            Enrichment::save($artist, 'mbid', $mbid, 'string', Enrichment::MUSICBRAINZ);
        }

        return $artist;
    }

    private function group(string $id, string $title, string $type = 'Album', string $date = '2002-10-22'): array
    {
        return ['id' => $id, 'title' => $title, 'primary-type' => $type, 'secondary-types' => [], 'first-release-date' => $date, 'disambiguation' => ''];
    }

    private function sync(Artist $artist, array $groups): void
    {
        Http::fake(['musicbrainz.org/ws/2/release-group*' => Http::response(['release-groups' => $groups, 'release-group-count' => count($groups)])]);
        (new ArtistCatalogueSync)->sync($artist);
    }

    private function album(Artist $artist, string $name, ?string $date, int $tracks = 10, string $source = 'files'): Album
    {
        $album = Album::create(['artist_id' => $artist->id, 'name' => $name, 'source' => $source, 'released_at' => $date]);
        for ($i = 1; $i <= $tracks; $i++) {
            Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => "T{$i}", 'source' => $source, 'external_id' => "{$source}:{$album->id}:{$i}"]);
        }

        CatalogueLinker::link($album->fresh()); // the model event ran before the tracks existed, like in a scan

        return $album->fresh();
    }

    private function row(string $id): CatalogueAlbum
    {
        return CatalogueAlbum::where('release_group_mbid', $id)->firstOrFail();
    }

    public function test_the_real_release_names_link_to_their_release_groups(): void
    {
        $artist = $this->artist();
        $this->sync($artist, [
            $this->group('rg-obo', 'One By One'),
            $this->group('rg-5s', 'Five Songs and a Cover', 'EP', '2005-09-20'),
        ]);

        $obo = $this->album($artist, 'One By One Special Norwegian Edition (82876 512002)', '2002-11-01', 12);
        $ep = $this->album($artist, 'Five Songs And A Cover US Best Buy Exclusive EP (BMK 96143)', '2005-09-20', 6);

        $this->assertSame($obo->id, $this->row('rg-obo')->album_id);
        $this->assertSame('edition', $this->row('rg-obo')->link_method);
        $this->assertSame($ep->id, $this->row('rg-5s')->album_id);
        $this->assertNotNull($this->row('rg-5s')->fulfilled_at, 'owned as a pressing: not missing');
    }

    public function test_a_release_style_single_stays_unlinked(): void
    {
        $artist = $this->artist();
        $this->sync($artist, [$this->group('rg-tcats', 'The Colour and the Shape', 'Album', '1997-05-20')]);

        $single = $this->album($artist, 'Everlong UK CD1 (7243 8 84361 2 0)', '1997-08-18', 3);

        $this->assertNull($this->row('rg-tcats')->album_id);
        $this->assertNull(CatalogueLinker::link($single));
    }

    public function test_a_short_release_with_the_name_of_an_album_is_a_single_not_the_album(): void
    {
        $artist = $this->artist();
        $this->sync($artist, [$this->group('rg-wl', 'Wasting Light', 'Album', '2011-04-12')]);

        $this->album($artist, 'Wasting Light UK CD1 (88697 90000 2)', '2011-04-12', 2);

        $this->assertNull($this->row('rg-wl')->album_id, 'a 2-track release is a single');
    }

    public function test_year_tolerance_for_a_loose_match(): void
    {
        $artist = $this->artist();
        $this->sync($artist, [$this->group('rg-obo', 'One By One', 'Album', '2002-10-22')]);

        $this->album($artist, 'One By One Japan (EICP 100)', '2008-01-01', 12);
        $this->assertNull($this->row('rg-obo')->album_id, 'six years off: another work');

        $this->album($artist, 'One By One EU (82876 512002)', '2004-01-01', 12);
        $this->assertNotNull($this->row('rg-obo')->album_id, 'two years off: a later pressing');
    }

    public function test_exact_beats_loose_whatever_the_order(): void
    {
        $artist = $this->artist();
        $this->sync($artist, [$this->group('rg-obo', 'One By One')]);

        $loose = $this->album($artist, 'One By One Special Norwegian Edition (82876 512002)', '2002-11-01', 12);
        $this->assertSame($loose->id, $this->row('rg-obo')->album_id);

        $exact = $this->album($artist, 'One By One', '2002-10-22', 11);
        $this->assertSame($exact->id, $this->row('rg-obo')->fresh()->album_id, 'the plain title takes over from an edition match');
        $this->assertSame('name', $this->row('rg-obo')->link_method);

        // and in one reconcile pass, with both albums unlinked
        CatalogueAlbum::query()->update(['album_id' => null, 'link_method' => null]);
        CatalogueLinker::relink($artist);
        $this->assertSame($exact->id, $this->row('rg-obo')->fresh()->album_id);
    }

    public function test_an_owned_edition_replaces_an_unowned_one_and_an_album_links_once(): void
    {
        $artist = $this->artist();
        $this->sync($artist, [$this->group('rg-obo', 'One By One')]);

        $streaming = $this->album($artist, 'One By One EU (82876 512002)', '2002-11-01', 12, 'spotify');
        $this->assertSame($streaming->id, $this->row('rg-obo')->album_id);
        $this->assertNull($this->row('rg-obo')->fulfilled_at);

        $owned = $this->album($artist, 'One By One UK (82876 512003)', '2002-11-01', 12);
        $this->assertSame($owned->id, $this->row('rg-obo')->fresh()->album_id);
        $this->assertNotNull($this->row('rg-obo')->fulfilled_at);
        $this->assertSame(1, CatalogueAlbum::where('album_id', $owned->id)->count());
    }

    public function test_different_works_and_numbers_do_not_link(): void
    {
        $artist = $this->artist();
        $this->sync($artist, [
            $this->group('rg-reading', 'Reading', 'Album', '2003'),
            $this->group('rg-gh', 'Greatest Hits', 'Album', '2009'),
            $this->group('rg-v1', 'Volume 1', 'Album', '2009'),
        ]);

        $this->album($artist, 'Live at Reading (CDEST 100)', '2003', 12);
        $this->album($artist, 'Greatest Hits Vol 2 (CDEST 200)', '2009', 12);
        $this->album($artist, 'Volume 2 (CDEST 300)', '2009', 12);

        $this->assertSame(0, CatalogueAlbum::whereNotNull('album_id')->count());
    }

    public function test_an_unrelated_album_with_the_same_loose_key_keeps_the_row_missing(): void
    {
        $artist = $this->artist();
        $this->sync($artist, [$this->group('rg-sh', 'Sonic Highways', 'Album', '2014')]);
        $row = $this->row('rg-sh');
        $row->update(['wanted_at' => now()]);

        // a different decade: same loose key, not the same record
        $this->album($artist, 'Sonic Highways (Japan) (ABCD 1234)', '1989', 12);

        $this->assertNull($row->fresh()->album_id);
        $this->assertNull($row->fresh()->fulfilled_at, 'the wish stays open');
    }

    public function test_owning_an_edition_closes_the_wish(): void
    {
        $artist = $this->artist();
        $this->sync($artist, [$this->group('rg-obo', 'One By One')]);
        $this->row('rg-obo')->update(['wanted_at' => now()]);

        $this->album($artist, 'One By One Special Norwegian Edition (82876 512002)', '2002-11-01', 12);

        $this->assertNotNull($this->row('rg-obo')->fulfilled_at);
    }

    public function test_several_albums_competing_for_one_group_link_only_one_and_ties_do_not_guess(): void
    {
        $artist = $this->artist();
        $this->sync($artist, [
            $this->group('rg-blue', 'Weezer', 'Album', '1994'),
            $this->group('rg-green', 'Weezer', 'Album', '2001'),
        ]);

        $this->album($artist, 'Weezer (Japan) (MVCG 1)', null, 10);
        $this->assertSame(0, CatalogueAlbum::whereNotNull('album_id')->count(), 'two groups, no year: no guess');
    }

    public function test_verification_accepts_a_release_style_library_and_refuses_a_compilation(): void
    {
        $artist = $this->artist(null);
        $this->album($artist, 'One By One Special Norwegian Edition (82876 512002)', '2002-11-01', 12);
        Http::fake([
            'musicbrainz.org/ws/2/artist*' => Http::response(['artists' => [['id' => 'art-9', 'name' => 'Foo Fighters', 'score' => 100]]]),
            'musicbrainz.org/ws/2/release-group*' => Http::response(['release-groups' => [$this->group('rg-obo', 'One By One')]]),
        ]);

        $this->assertSame('ok', (new ArtistCatalogueSync)->sync($artist));
        $this->assertSame('art-9', Enrichment::mbid($artist));

    }

    public function test_verification_refuses_a_compilation_only_artist(): void
    {
        $other = Artist::create(['name' => 'Feist Like', 'source' => 'files']);
        $this->album($other, 'Now That\'s What I Call Music (NOW 99)', '2007', 20);
        Http::fake([
            'musicbrainz.org/ws/2/artist*' => Http::response(['artists' => [['id' => 'art-8', 'name' => 'Feist Like', 'score' => 100]]]),
            'musicbrainz.org/ws/2/release-group*' => Http::response(['release-groups' => [$this->group('rg-x', 'Let It Die', 'Album', '2004')]]),
        ]);
        $this->assertSame('unverified', (new ArtistCatalogueSync)->sync($other));
        $this->assertNull(Enrichment::mbid($other));
    }

    public function test_reconcile_relinks_synced_rows_is_idempotent_and_never_touches_name_keys(): void
    {
        $artist = $this->artist();
        $this->sync($artist, [$this->group('rg-obo', 'One By One')]);
        $album = $this->album($artist, 'One By One Special Norwegian Edition (82876 512002)', '2002-11-01', 12);
        CatalogueAlbum::query()->update(['album_id' => null, 'link_method' => null, 'fulfilled_at' => null]); // as synced before edition matching

        $keys = Album::orderBy('id')->pluck('name_key', 'id')->all();
        $this->assertSame(Normalizer::album($album->name), $keys[$album->id]);
        $this->assertNotSame(Normalizer::album('One By One'), $keys[$album->id], 'identity still tells the pressing apart');

        $this->artisan('catalogue:reconcile --dry-run --artist='.$artist->id)->expectsOutputToContain('1 link(s) made or moved')->assertSuccessful();
        $this->assertNull($this->row('rg-obo')->album_id, 'a dry run saves nothing');

        $this->artisan('catalogue:reconcile')->expectsOutputToContain('1 link(s) made or moved')->assertSuccessful();
        $this->assertSame($album->id, $this->row('rg-obo')->album_id);

        $this->artisan('catalogue:reconcile')->expectsOutputToContain('0 link(s) made or moved')->assertSuccessful();
        $this->assertSame($keys, Album::orderBy('id')->pluck('name_key', 'id')->all());
        $this->assertSame(1, Normalizer::VERSION, 'the library key rules are untouched');
    }
}
