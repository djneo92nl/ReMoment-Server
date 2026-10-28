<?php

namespace Tests\Feature;

use App\Domain\Catalogue\ArtistCatalogueSync;
use App\Domain\Catalogue\CatalogueLinker;
use App\Domain\Catalogue\PhysicalItems;
use App\Domain\Library\Enrichment;
use App\Livewire\PhysicalCollection;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Client;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\PhysicalItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

/** The physical collection: items by barcode or by hand, owned in the availability layer, wishes closed by a CD on the shelf (docs/api/physical.md). */
class PhysicalCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Http::preventStrayRequests();
    }

    private function release(): array
    {
        return ['id' => 'rel-1', 'title' => 'First Album', 'date' => '1999-05-01', 'country' => 'NL', 'release-group' => ['id' => 'rg-1'],
            'artist-credit' => [['name' => 'Band', 'joinphrase' => ' & '], ['name' => 'Friend']], 'label-info' => [['catalog-number' => 'CAT 1', 'label' => ['name' => 'Label']]], 'media' => [['format' => 'Vinyl']]];
    }

    private function wished(string $rg = 'rg-1', ?Artist $artist = null): CatalogueAlbum
    {
        $artist ??= Artist::create(['name' => 'Band']);

        return CatalogueAlbum::create(['artist_id' => $artist->id, 'release_group_mbid' => $rg, 'title' => 'First Album', 'type' => 'album', 'seen_at' => now(), 'wanted_at' => now()]);
    }

    public function test_barcode_lookup_reads_the_releases(): void
    {
        Http::fake(['musicbrainz.org/ws/2/release*' => Http::response(['releases' => [$this->release()]])]);

        $found = PhysicalItems::lookupBarcode('0602 537-531159');

        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'barcode:0602537531159'));
        $this->assertSame(['rel-1', 'rg-1', 'Band & Friend', 'First Album', 1999, 'Label', 'CAT 1', 'NL', 'vinyl'],
            [$found[0]['release_mbid'], $found[0]['release_group_mbid'], $found[0]['artist'], $found[0]['title'], $found[0]['year'], $found[0]['label'], $found[0]['catalogue_number'], $found[0]['country'], $found[0]['medium']]);
        $this->assertSame([], PhysicalItems::lookupBarcode('abc'));
    }

    public function test_api_add_list_filter_update_remove(): void
    {
        $this->postJson('/api/library/physical', ['artist' => 'Band', 'title' => 'First Album', 'medium' => 'cd', 'year' => 1999, 'location' => 'Shelf B2', 'barcode' => '123456789012'])
            ->assertCreated()->assertJson(['artist' => 'Band', 'medium' => 'cd', 'location' => 'Shelf B2', 'ripped' => false]);
        $tape = $this->postJson('/api/library/physical', ['artist' => 'Other', 'title' => 'Demo', 'medium' => 'tape'])->assertCreated()->json();

        $this->assertSame(['First Album', 'Demo'], array_column($this->getJson('/api/library/physical')->assertOk()->json('data'), 'title'));
        $this->assertSame(['Demo'], array_column($this->getJson('/api/library/physical?medium=tape')->json('data'), 'title'));
        $this->assertSame(['First Album'], array_column($this->getJson('/api/library/physical?q=123456')->json('data'), 'title'));

        $this->putJson("/api/library/physical/{$tape['id']}", ['ripped' => true, 'location' => 'Box 4'])->assertOk()->assertJson(['ripped' => true, 'location' => 'Box 4']);
        $this->assertSame(['Demo'], array_column($this->getJson('/api/library/physical?ripped=1')->json('data'), 'title'));
        $this->putJson("/api/library/physical/{$tape['id']}", ['ripped' => false])->assertJson(['ripped' => false]);

        $this->postJson('/api/library/physical', ['artist' => 'X', 'title' => 'Y', 'medium' => 'laserdisc'])->assertStatus(422);
        $this->postJson('/api/library/physical', ['title' => 'No artist', 'medium' => 'cd'])->assertStatus(422);
        $this->getJson("/api/library/physical/{$tape['id']}")->assertOk();
        $this->deleteJson("/api/library/physical/{$tape['id']}")->assertOk();
        $this->getJson("/api/library/physical/{$tape['id']}")->assertNotFound();
    }

    public function test_lookup_endpoint_and_failure(): void
    {
        Http::fake(['musicbrainz.org/ws/2/release*' => Http::sequence()->push(['releases' => [$this->release()]])->push('busy', 503)]);

        $this->getJson('/api/library/physical/lookup?barcode=0602537531159')->assertOk()->assertJsonPath('data.0.title', 'First Album');
        $this->getJson('/api/library/physical/lookup?barcode=0602537531159')->assertStatus(502)->assertJson(['error' => 'driver_error']);
        $this->getJson('/api/library/physical/lookup?barcode=x')->assertStatus(422);
    }

    public function test_client_devices_are_refused(): void
    {
        Client::create(['name' => 'Touch', 'type' => 'multi', 'status' => 'approved', 'api_token' => 'tok', 'registration_token' => 'reg']);

        $this->getJson('/api/library/physical?client=tok')->assertForbidden();
        $this->postJson('/api/library/physical?client=tok', ['artist' => 'A', 'title' => 'B', 'medium' => 'cd'])->assertForbidden();
        $this->assertSame(0, PhysicalItem::count());
    }

    public function test_an_item_closes_the_wish_on_its_release_group_and_removing_it_opens_it_again(): void
    {
        $row = $this->wished();
        $item = $this->postJson('/api/library/physical', ['artist' => 'Band', 'title' => 'First Album', 'medium' => 'vinyl', 'release_group_mbid' => '11111111-1111-1111-1111-111111111111'])->json();
        $this->assertNull($row->fresh()->fulfilled_at, 'another release-group');

        $row->update(['release_group_mbid' => '11111111-1111-1111-1111-111111111111']);
        $this->putJson("/api/library/physical/{$item['id']}", ['location' => 'moved'])->assertOk();
        $this->assertNotNull($row->fresh()->fulfilled_at);
        $this->assertSame([], $this->getJson('/api/library/wishlist')->json('data'));
        $this->assertSame('owned', $this->getJson("/api/library/catalogue/{$row->id}")->json('state'));
        $this->assertSame(['physical'], array_column($this->getJson("/api/library/catalogue/{$row->id}")->json('availability'), 'provider'));

        $this->deleteJson("/api/library/physical/{$item['id']}")->assertOk();
        $this->assertNull($row->fresh()->fulfilled_at);
        $this->assertSame('known', $this->getJson("/api/library/catalogue/{$row->id}")->json('state'));
    }

    public function test_an_item_links_to_the_library_album_and_a_later_album_takes_over_the_item(): void
    {
        $artist = Artist::create(['name' => 'Band']);
        $item = PhysicalItems::add(['artist' => 'The Band', 'title' => 'First Album (Remastered)', 'medium' => 'cd']);
        $this->assertNull($item->album_id);

        $album = Album::create(['artist_id' => $artist->id, 'name' => 'First Album', 'source' => 'files']);

        $this->assertSame($album->id, $item->fresh()->album_id, 'the album that appears later is tied to the item');
        $this->assertTrue(CatalogueLinker::isOwned($album), 'a CD on the shelf makes the album owned');

        $second = PhysicalItems::add(['artist' => 'Band', 'title' => 'First Album', 'medium' => 'vinyl']);
        $this->assertSame($album->id, $second->album_id, 'a CD and a vinyl share the album');
    }

    public function test_a_discography_sync_marks_shelved_release_groups_fulfilled(): void
    {
        $artist = Artist::create(['name' => 'Band']);
        Enrichment::save($artist, 'mbid', 'art-1', 'string', Enrichment::MUSICBRAINZ);
        PhysicalItems::add(['artist' => 'Band', 'title' => 'First Album', 'medium' => 'cd', 'release_group_mbid' => '22222222-2222-2222-2222-222222222222']);
        Http::fake(['musicbrainz.org/ws/2/release-group*' => Http::response(['release-groups' => [
            ['id' => '22222222-2222-2222-2222-222222222222', 'title' => 'First Album', 'primary-type' => 'Album', 'secondary-types' => [], 'first-release-date' => '1999'],
        ]])]);

        (new ArtistCatalogueSync)->sync($artist);

        $this->assertNotNull(CatalogueAlbum::first()->fulfilled_at);
    }

    public function test_the_web_collection_adds_by_hand_and_by_barcode_and_filters(): void
    {
        Http::fake(['musicbrainz.org/ws/2/release*' => Http::response(['releases' => [$this->release()]])]);

        $this->get('/library/collection')->assertOk()->assertSee('Your collection is empty');

        Livewire::test(PhysicalCollection::class)
            ->set('barcode', '0602537531159')->call('lookup')->assertSee('First Album')->assertSee('Use this')
            ->call('choose', 0)->set('location', 'Shelf B2')->call('save')
            ->assertSee('Added First Album by Band & Friend.')->assertSee('Shelf B2');
        $this->assertSame(['rg-1', 'vinyl'], [PhysicalItem::first()->release_group_mbid, PhysicalItem::first()->medium]);

        Livewire::test(PhysicalCollection::class)
            ->set('artist', 'Tape Man')->set('title', 'Demo')->set('newMedium', 'tape')->call('save')
            ->set('medium', 'tape')->assertSee('Demo')->assertDontSee('First Album')
            ->set('medium', '')->call('toggleRipped', PhysicalItem::where('title', 'Demo')->value('id'))->assertSee('Ripped')
            ->call('remove', PhysicalItem::where('title', 'Demo')->value('id'))->assertDontSee('Remove Demo')
            ->set('barcode', 'abc')->call('lookup')->assertSee('A barcode is 6 to 20 digits.');
        $this->get('/library')->assertOk()->assertSee('Collection');
    }

    public function test_mcp_physical_tool(): void
    {
        ReMomentServer::tool(Tools\Physical::class, ['action' => 'add', 'artist' => 'Band', 'title' => 'LP', 'medium' => 'cd'])->assertOk()->assertSee('Band');
        $id = PhysicalItem::first()->id;
        ReMomentServer::tool(Tools\Physical::class, ['action' => 'list', 'q' => 'LP'])->assertOk()->assertSee('LP');
        ReMomentServer::tool(Tools\Physical::class, ['action' => 'update', 'id' => $id, 'ripped' => true])->assertOk()->assertSee('"ripped":true', false);
        ReMomentServer::tool(Tools\Physical::class, ['action' => 'update'])->assertHasErrors(['needs an id']);
        ReMomentServer::tool(Tools\Physical::class, ['action' => 'remove', 'id' => $id])->assertOk();
        $this->assertSame(0, PhysicalItem::count());
    }
}
