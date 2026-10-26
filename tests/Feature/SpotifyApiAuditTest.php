<?php

namespace Tests\Feature;

use App\Domain\Spotify\SpotifyLibraryApi;
use App\Integrations\Spotify\MusicPlayerDriver;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Jobs\EnrichTrackSpotify;
use App\Jobs\FetchSpotifyArtistImages;
use App\Models\Device;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Services\SpotifyTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use SpotifyWebAPI\SpotifyWebAPIException;
use Tests\TestCase;

/** The calls changed or guarded for Spotify's February 2026 Development Mode rules (docs/architecture/spotify-api-audit.md). */
class SpotifyApiAuditTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.spotify.com/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['spotify.api_url' => self::API]);
        $this->mock(SpotifyTokenService::class, function ($mock) {
            $mock->shouldReceive('getAccessToken')->andReturn('tok');
            $mock->shouldReceive('isConnected')->andReturn(true);
        });
    }

    private function api(): SpotifyLibraryApi
    {
        return app(SpotifyLibraryApi::class);
    }

    public function test_saving_a_track_uses_the_generic_library_endpoint_with_a_uri(): void
    {
        Http::fake([self::API.'/me/library*' => Http::response('', 200)]);

        $this->api()->setTrackSaved('abc', true);
        $this->api()->setTrackSaved('abc', false);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_starts_with($r->url(), self::API.'/me/library?') && str_contains($r->url(), 'uris=spotify%3Atrack%3Aabc') && $r->hasHeader('Authorization', 'Bearer tok'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_starts_with($r->url(), self::API.'/me/library?'));
    }

    public function test_saving_falls_back_to_the_old_endpoint_where_the_new_one_is_missing(): void
    {
        Http::fake([
            self::API.'/me/library*' => Http::response('', 404),
            self::API.'/me/tracks*' => Http::response('', 200),
        ]);

        $this->api()->setTrackSaved('abc', true);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/me/tracks') && str_contains($r->url(), 'ids=abc'));
    }

    public function test_a_rejected_save_throws_instead_of_pretending(): void
    {
        Http::fake([self::API.'/me/library*' => Http::response(['error' => ['message' => 'Insufficient client scope']], 403)]);

        $this->expectException(SpotifyWebAPIException::class);
        $this->api()->setTrackSaved('abc', true);
    }

    public function test_liked_check_uses_library_contains_and_falls_back(): void
    {
        Http::fake([
            self::API.'/me/library/contains*' => Http::response('', 410),
            self::API.'/me/tracks/contains*' => Http::response([false]),
        ]);
        $this->assertFalse($this->api()->isTrackSaved('abc'));
    }

    public function test_liked_check_reads_the_library_contains_answer(): void
    {
        Http::fake([self::API.'/me/library/contains*' => Http::response([true])]);

        $this->assertTrue($this->api()->isTrackSaved('abc'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'uris=spotify%3Atrack%3Aabc'));
    }

    public function test_set_liked_on_the_driver_goes_through_the_library_endpoint(): void
    {
        $sdk = Mockery::mock(SpotifyWebAPI::class);
        $sdk->shouldReceive('getMyCurrentPlaybackInfo')->andReturn(['item' => ['id' => 'abc']]);
        $this->app->instance(SpotifyTokenService::class, tap(Mockery::mock(SpotifyTokenService::class), function ($m) use ($sdk) {
            $m->shouldReceive('makeApiClient')->andReturn($sdk);
            $m->shouldReceive('getAccessToken')->andReturn('tok');
        }));
        Http::fake([self::API.'/me/library*' => Http::response('', 200)]);

        (new MusicPlayerDriver(Device::factory()->create()))->setLiked(true);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/me/library'));
        $this->assertTrue(cache()->get(MusicPlayerDriver::likedCacheKey('abc')));
    }

    public function test_playlist_entries_use_items_and_accept_an_item_key(): void
    {
        Http::fake([self::API.'/playlists/p1/items*' => Http::response(['total' => 1, 'items' => [['item' => ['id' => 't1']]]])]);

        $page = $this->api()->playlistEntries('p1', 0, 50);

        $this->assertSame('t1', $page['items'][0]['track']['id']);
        $this->assertSame(1, $page['total']);
    }

    public function test_playlist_entries_of_a_playlist_spotify_will_not_list_are_null(): void
    {
        Http::fake([self::API.'/playlists/*' => Http::response('', 403)]);

        $this->assertNull($this->api()->playlistEntries('p1', 0, 50));
    }

    public function test_playlist_entries_fall_back_to_the_old_tracks_endpoint(): void
    {
        Http::fake([
            self::API.'/playlists/p1/items*' => Http::response('', 404),
            self::API.'/playlists/p1/tracks*' => Http::response(['total' => 1, 'items' => [['track' => ['id' => 't1']]]]),
        ]);

        $this->assertSame('t1', $this->api()->playlistEntries('p1', 0, 50)['items'][0]['track']['id']);
    }

    public function test_playlist_import_keeps_a_playlist_whose_entries_are_hidden_and_does_not_fail(): void
    {
        $sdk = Mockery::mock(SpotifyWebAPI::class);
        $sdk->shouldReceive('getMyPlaylists')->andReturn(['total' => 1, 'items' => [
            ['id' => 'p1', 'name' => 'Followed', 'description' => null, 'images' => [], 'owner' => ['display_name' => 'Someone']],
        ]]);
        $this->app->instance(SpotifyTokenService::class, tap(Mockery::mock(SpotifyTokenService::class), function ($m) use ($sdk) {
            $m->shouldReceive('makeApiClient')->andReturn($sdk);
            $m->shouldReceive('getAccessToken')->andReturn('tok');
        }));
        Http::fake([self::API.'/playlists/*' => Http::response('', 403)]);

        $this->assertSame(1, app(SpotifyLibraryImporter::class)->importPlaylists());
        $this->assertDatabaseHas('playlists', ['external_id' => 'spotify:playlist:p1']);
    }

    public function test_artist_photos_fall_back_to_single_lookups_when_the_batch_endpoint_is_gone(): void
    {
        $artist = Artist::create(['name' => 'Band']);
        \App\Domain\Library\Enrichment::save($artist, 'spotify_id', 'sp1', 'string', \App\Domain\Library\Enrichment::SPOTIFY);

        $sdk = Mockery::mock(SpotifyWebAPI::class);
        $sdk->shouldReceive('getArtists')->andThrow(new SpotifyWebAPIException('gone', 403));
        $sdk->shouldReceive('getArtist')->with('sp1')->once()->andReturn(['id' => 'sp1', 'images' => [['url' => 'https://i/x.jpg', 'width' => 640]]]);
        $this->app->instance(SpotifyTokenService::class, tap(Mockery::mock(SpotifyTokenService::class), function ($m) use ($sdk) {
            $m->shouldReceive('isConnected')->andReturn(true);
            $m->shouldReceive('makeApiClient')->andReturn($sdk);
        }));

        (new FetchSpotifyArtistImages([$artist->id]))->handle(app(SpotifyTokenService::class));

        $this->assertSame('https://i/x.jpg', $artist->fresh()->metaValue('spotify_image'));
    }

    public function test_track_enrichment_with_a_removed_endpoint_degrades_to_no_data(): void
    {
        $artist = Artist::create(['name' => 'Band']);
        $track = Track::create(['name' => 'Song', 'artist_id' => $artist->id, 'source' => 'spotify', 'external_id' => 'spotify:track:abc']);

        $sdk = Mockery::mock(SpotifyWebAPI::class);
        $sdk->shouldReceive('getTrack')->andThrow(new SpotifyWebAPIException('Forbidden', 403));
        $this->app->instance(SpotifyTokenService::class, tap(Mockery::mock(SpotifyTokenService::class), function ($m) use ($sdk) {
            $m->shouldReceive('isConnected')->andReturn(true);
            $m->shouldReceive('makeApiClient')->andReturn($sdk);
        }));

        (new EnrichTrackSpotify($track))->handle(app(SpotifyTokenService::class));

        $this->assertTrue(\App\Domain\Library\Enrichment::isDone($track->fresh(), \App\Domain\Library\Enrichment::SPOTIFY));
    }
}
