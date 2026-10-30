<?php

namespace Tests\Feature\Api;

use App\Domain\Library\LeadingSource;
use App\Models\Client;
use App\Models\Media\Album;
use App\Models\Media\AlbumAvailability;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\CatalogueArtist;
use App\Models\Media\PhysicalItem;
use App\Models\Media\Track;
use App\Models\Media\WantedTrack;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CachesArtwork;
use Tests\TestCase;

/**
 * Risk 7 of docs/architecture/availability-layer.md: the catalogue (missing albums, wishes, lookup answers, wished songs,
 * physical items) is opt-in. Clients and the existing library endpoints must answer exactly as they did without those rows:
 * a missing album is never a library album, never in a count, never in the artwork pre-cache and never a random pick.
 */
class CatalogueClientSafetyTest extends TestCase
{
    use CachesArtwork, RefreshDatabase;

    private Artist $artist;

    /** @var list<Album> */
    private array $albums = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set(LeadingSource::SETTING, 'all');

        $this->artist = Artist::create(['name' => 'Band', 'source' => 'files']);
        foreach (['First', 'Second'] as $i => $name) {
            $url = "https://x.test/{$name}.jpg";
            $album = Album::create(['artist_id' => $this->artist->id, 'name' => $name, 'source' => 'files', 'images' => [['url' => $url]]]);
            Track::create(['album_id' => $album->id, 'artist_id' => $this->artist->id, 'name' => "Song {$i}", 'source' => 'files']);
            $this->cacheProcessedArtwork($url);
            $this->albums[] = $album;
        }
    }

    /** @return array<string, string> label => URL of every client-facing and library list endpoint that must not change */
    private function endpoints(): array
    {
        $token = Client::create([
            'status' => 'approved', 'type' => 'multi',
            'registration_token' => Client::generateToken(), 'api_token' => Client::generateToken(),
        ])->api_token;

        return [
            'client artwork' => "/api/clients/{$token}/artwork",
            'albums' => '/api/library/albums',
            'albums by year' => '/api/library/albums?sort=year',
            'artists' => '/api/library/artists',
            'artist' => "/api/library/artists/{$this->artist->id}",
            'album' => "/api/library/albums/{$this->albums[0]->id}",
            'tracks' => '/api/library/tracks',
            'libraries' => '/api/library/libraries',
            'genres' => '/api/library/genres',
            'recent' => '/api/library/recent',
            'recent items' => '/api/library/recent-items',
            'favorites' => '/api/library/favorites',
            'playlists' => '/api/library/playlists',
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $out = [];
        foreach ($this->endpoints() as $label => $url) {
            $response = $this->getJson($url);
            $out[$label] = [$response->status(), $response->json()];
        }

        return $out;
    }

    private function addCatalogueRows(): void
    {
        // Missing albums, one wished, one ignored, one linked to a library album, and one title that equals a library album's.
        foreach ([
            ['rg-1', 'Never Owned', ['wanted_at' => now()]],
            ['rg-2', 'Not Interested', ['ignored_at' => now()]],
            ['rg-3', 'Third Record', []],
            ['rg-4', 'Second', ['album_id' => $this->albums[1]->id, 'linked_at' => now(), 'link_method' => 'name', 'fulfilled_at' => now()]],
            ['rg-5', 'First', []],
        ] as [$mbid, $title, $extra]) {
            CatalogueAlbum::create(['artist_id' => $this->artist->id, 'release_group_mbid' => $mbid, 'title' => $title, 'type' => 'album', 'first_release_year' => 1999, 'seen_at' => now()] + $extra);
        }
        CatalogueArtist::create(['artist_id' => $this->artist->id, 'mbid' => 'a-1', 'status' => 'ok', 'release_group_count' => 5, 'synced_at' => now()]);
        $row = CatalogueAlbum::where('release_group_mbid', 'rg-3')->first();
        AlbumAvailability::create(['catalogue_album_id' => $row->id, 'provider' => 'spotify', 'status' => 'available', 'external_id' => 'sp-1', 'confidence' => 90, 'match' => 'search', 'checked_at' => now()]);
        AlbumAvailability::create(['album_id' => $this->albums[0]->id, 'provider' => 'spotify', 'status' => 'available', 'external_id' => 'sp-2', 'confidence' => 90, 'match' => 'link', 'checked_at' => now()]);

        // An artist whose only trace is a discography (no library album), and a wished song and a shelf item that are not in the library.
        $ghost = Artist::create(['name' => 'Ghost', 'source' => 'files']);
        CatalogueAlbum::create(['artist_id' => $ghost->id, 'release_group_mbid' => 'rg-9', 'title' => 'Ghost Album', 'type' => 'album', 'seen_at' => now()]);
        WantedTrack::create(['artist' => 'Ghost', 'title' => 'Wished', 'artist_key' => 'ghost', 'title_key' => 'wished', 'source' => 'manual', 'wanted_at' => now()]);
        PhysicalItem::create(['artist' => 'Ghost', 'title' => 'On The Shelf', 'artist_key' => 'ghost', 'title_key' => 'on the shelf', 'medium' => 'cd']);
    }

    public function test_catalogue_rows_change_nothing_for_clients_and_the_library_endpoints(): void
    {
        $before = $this->snapshot();
        $this->assertSame(200, $before['client artwork'][0]);
        $this->assertCount(2, $before['albums'][1]['data']);
        $this->assertSame(['First', 'Second'], array_column($before['albums'][1]['data'], 'name'));

        $this->addCatalogueRows();
        $after = $this->snapshot();

        foreach ($before as $label => $answer) {
            $this->assertSame($answer, $after[$label], "{$label} changed when catalogue rows were added");
        }
        $this->assertSame(2, $after['artists'][1]['data'][0]['album_count'] ?? null, 'the two library albums, not the seven catalogue rows');
        $this->assertCount(1, $after['artists'][1]['data']);
    }

    public function test_the_random_album_is_never_a_missing_album(): void
    {
        $this->addCatalogueRows();
        $libraryIds = array_map(fn ($a) => $a->id, $this->albums);

        for ($i = 0; $i < 15; $i++) {
            $id = $this->getJson('/api/library/random-album')->assertOk()->json('data.id');
            $this->assertContains($id, $libraryIds);
        }
    }

    public function test_the_catalogue_endpoints_stay_closed_to_client_tokens(): void
    {
        $this->addCatalogueRows();
        $token = Client::create(['status' => 'approved', 'type' => 'multi', 'registration_token' => Client::generateToken(), 'api_token' => Client::generateToken()])->api_token;
        $row = CatalogueAlbum::first();

        $this->getJson("/api/library/artists/{$this->artist->id}/discography?client={$token}")->assertForbidden();
        $this->getJson("/api/library/wishlist?client={$token}")->assertForbidden();
        $this->getJson("/api/library/catalogue/{$row->id}?client={$token}")->assertForbidden();
    }
}
