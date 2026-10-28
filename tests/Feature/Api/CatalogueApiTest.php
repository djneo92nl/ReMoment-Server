<?php

namespace Tests\Feature\Api;

use App\Domain\Catalogue\CatalogueLinker;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Client;
use App\Models\Media\Album;
use App\Models\Media\AlbumAvailability;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /api/library/artists/{id}/discography, /wishlist, /catalogue/{id}, include=discography and ?availability= (docs/api/availability.md). */
class CatalogueApiTest extends TestCase
{
    use RefreshDatabase;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artist = Artist::create(['name' => 'Band', 'source' => 'files']);
    }

    private function row(string $title, int $year, array $extra = []): CatalogueAlbum
    {
        return CatalogueAlbum::create($extra + [
            'artist_id' => $this->artist->id, 'release_group_mbid' => 'rg-'.md5($title), 'title' => $title, 'type' => 'album',
            'first_release_year' => $year, 'first_release_date' => (string) $year, 'seen_at' => now(),
        ]);
    }

    private function album(string $name, string $source): Album
    {
        $album = Album::create(['artist_id' => $this->artist->id, 'name' => $name, 'source' => $source]);
        Track::create(['album_id' => $album->id, 'artist_id' => $this->artist->id, 'name' => 'T', 'source' => $source, 'external_id' => $source.':'.$album->id]);

        return $album;
    }

    public function test_discography_marks_owned_streamable_and_known_newest_first(): void
    {
        $this->row('Known One', 2010);
        $this->row('Spotify One', 2005);
        $this->row('Mine', 2000);
        $this->album('Mine', 'files');
        $this->album('Spotify One', 'spotify');

        $data = $this->getJson("/api/library/artists/{$this->artist->id}/discography")->assertOk()->json('data');

        $this->assertSame(['Known One', 'Spotify One', 'Mine'], array_column($data, 'title'));
        $this->assertSame(['known', 'streamable', 'owned'], array_column($data, 'state'));
        $this->assertNull($data[0]['album_id']);
        $this->assertSame('spotify', $data[1]['availability'][0]['library']);
        $this->assertNotNull($data[2]['album_id']);
        $this->assertArrayNotHasKey('playable', $data[1]['availability'][0]);
    }

    public function test_a_lookup_answer_makes_an_unsaved_album_streamable(): void
    {
        $row = $this->row('Elsewhere', 2012);
        AlbumAvailability::create(['catalogue_album_id' => $row->id, 'provider' => 'spotify', 'status' => 'available', 'external_id' => 'spotify:album:x', 'confidence' => 90, 'match' => 'search', 'checked_at' => now()]);
        AlbumAvailability::create(['catalogue_album_id' => $this->row('Nope', 2013)->id, 'provider' => 'spotify', 'status' => 'unavailable', 'confidence' => 0, 'match' => 'search', 'checked_at' => now()]);

        $data = $this->getJson("/api/library/artists/{$this->artist->id}/discography")->json('data');

        $this->assertSame(['known', 'streamable'], array_column($data, 'state'));
        $this->assertNull($data[1]['album_id']);
    }

    public function test_filters_missing_wanted_and_ignored(): void
    {
        $this->row('Known', 2010, ['wanted_at' => now()]);
        $this->row('Hidden', 2009, ['ignored_at' => now()]);
        $this->row('Mine', 2000);
        $this->album('Mine', 'files');
        CatalogueLinker::linkArtist($this->artist);

        $titles = fn (string $q) => array_column($this->getJson("/api/library/artists/{$this->artist->id}/discography?{$q}")->assertOk()->json('data'), 'title');

        $this->assertSame(['Known', 'Mine'], $titles(''), 'ignored left out');
        $this->assertSame(['Known', 'Hidden', 'Mine'], $titles('ignored=1'));
        $this->assertSame(['Known'], $titles('availability=missing'));
        $this->assertSame(['Mine'], $titles('availability=owned'));
        $this->assertSame(['Known'], $titles('wanted=1'));
        $this->getJson("/api/library/artists/{$this->artist->id}/discography?availability=nope")->assertStatus(422);
    }

    public function test_wishlist_lists_wishes_that_are_not_fulfilled(): void
    {
        $this->row('Wanted', 2010, ['wanted_at' => now()->subMinute()]);
        $this->row('Newer wish', 2011, ['wanted_at' => now()]);
        $this->row('Owned wish', 2000, ['wanted_at' => now(), 'fulfilled_at' => now()]);
        $this->row('No wish', 2001);

        $data = $this->getJson('/api/library/wishlist')->assertOk()->json();

        $this->assertSame(['Newer wish', 'Wanted'], array_column($data['data'], 'title'));
        $this->assertSame('Band', $data['data'][0]['artist']['name']);
        $this->assertNull($data['next_cursor']);
    }

    public function test_item_and_playable_flag_for_a_device(): void
    {
        $row = $this->row('Spotify One', 2005);
        $this->album('Spotify One', 'spotify');
        CatalogueLinker::linkArtist($this->artist);
        $device = \App\Models\Device::factory()->create();

        $item = $this->getJson("/api/library/catalogue/{$row->id}?device_id={$device->id}")->assertOk()->json();

        $this->assertSame('streamable', $item['state']);
        $this->assertArrayHasKey('playable', $item['availability'][0]);
        $this->getJson('/api/library/catalogue/9999')->assertNotFound();
    }

    public function test_artist_detail_only_includes_the_discography_when_asked_and_never_for_a_client(): void
    {
        $this->row('Known', 2010);
        $this->album('Mine', 'files');
        $client = Client::create(['name' => 'Touch', 'type' => 'multi', 'status' => 'approved', 'api_token' => 'tok', 'registration_token' => 'reg']);

        $this->assertArrayNotHasKey('discography', $this->getJson("/api/library/artists/{$this->artist->id}")->json());
        $this->assertCount(1, $this->getJson("/api/library/artists/{$this->artist->id}?include=discography")->json('discography'));
        $this->assertArrayNotHasKey('discography', $this->getJson("/api/library/artists/{$this->artist->id}?include=discography&client=tok")->json());
        $this->getJson('/api/library/wishlist?client=tok')->assertForbidden();
        $this->getJson("/api/library/artists/{$this->artist->id}/discography?client=tok")->assertForbidden();
        unset($client);
    }

    public function test_missing_albums_never_reach_the_album_lists_or_counts(): void
    {
        $this->row('Known', 2010);
        $mine = $this->album('Mine', 'files');
        $this->album('Streamed', 'spotify');

        $this->assertSame(['Mine', 'Streamed'], collect($this->getJson('/api/library/albums?scope=all')->json('data'))->pluck('name')->sort()->values()->all());
        $this->assertSame(['Mine'], collect($this->getJson('/api/library/albums?scope=all&availability=owned')->json('data'))->pluck('name')->all());
        $this->assertSame(['Streamed'], collect($this->getJson('/api/library/albums?scope=all&availability=streamable')->json('data'))->pluck('name')->all());
        $this->getJson('/api/library/albums?availability=missing')->assertStatus(422);
        $this->assertNotContains('Known', collect($this->getJson("/api/library/artists/{$this->artist->id}")->json('albums'))->pluck('name')->all());
        unset($mine);
    }

    public function test_mcp_tool_wraps_the_endpoints(): void
    {
        $this->row('Known', 2010, ['wanted_at' => now()]);

        ReMomentServer::tool(Tools\Catalogue::class, ['action' => 'wishlist'])->assertOk()->assertSee('Known');
        ReMomentServer::tool(Tools\Catalogue::class, ['action' => 'discography', 'artist_id' => $this->artist->id, 'availability' => 'missing'])->assertOk()->assertSee('Known');
        ReMomentServer::tool(Tools\Catalogue::class, ['action' => 'discography'])->assertHasErrors(['artist_id']);
    }
}
