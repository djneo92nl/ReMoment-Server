<?php

namespace Tests\Feature\Api;

use App\Domain\Availability\ExternalCatalogues;
use App\Domain\Device\State;
use App\Domain\Library\LibrarySources;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Client;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakesSpotify;
use Tests\TestCase;

/** Browse, play and add what Spotify offers, artists the library does not have included (docs/api/availability.md). */
class ExternalCatalogueApiTest extends TestCase
{
    use FakesSpotify, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function api(): \Mockery\MockInterface
    {
        $api = Mockery::mock(SpotifyWebAPI::class);
        $this->connectSpotify($api);

        return $api;
    }

    private const ART = 'artist00000AB';

    private const ALB = 'album000000AB';

    private const TRK = 'track000000AB';

    private function spotifyAlbum(string $id, string $name, string $date = '1999-05-01'): array
    {
        return ['id' => $id, 'name' => $name, 'release_date' => $date, 'total_tracks' => 10, 'artists' => [['name' => 'Band']], 'images' => []];
    }

    public function test_the_registry_and_the_libraries_agree(): void
    {
        foreach (ExternalCatalogues::ids() as $id) {
            $this->assertArrayHasKey($id, LibrarySources::LIBRARIES, "provider {$id} needs a LibrarySources entry");
            $this->assertSame($id, ExternalCatalogues::for($id)->id());
        }
        $this->assertNull(ExternalCatalogues::for('nope'));
    }

    public function test_search_returns_items_and_marks_what_the_library_has(): void
    {
        $api = $this->api();
        $artist = Artist::create(['name' => 'Band', 'source' => 'files']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Known Album', 'source' => 'files']);
        $api->shouldReceive('search')->with('band', 'album', ['limit' => 10])->once()->andReturn(['albums' => ['items' => [
            $this->spotifyAlbum(self::ALB, 'Known Album'), $this->spotifyAlbum('album000000CD', 'New Album', '2020-01-01'),
        ]]]);

        $data = $this->getJson('/api/library/external/spotify/search?q=band&type=album')->assertOk()->json('data');

        $this->assertSame(['Known Album', 'New Album'], array_column($data, 'title'));
        $this->assertTrue($data[0]['in_library']);
        $this->assertSame($album->id, $data[0]['library_id']);
        $this->assertFalse($data[1]['in_library']);
        $this->assertSame(['spotify', 'album', self::ALB, 'Band', '1999', 10], [$data[0]['provider'], $data[0]['type'], $data[0]['id'], $data[0]['artist'], $data[0]['year'], $data[0]['track_count']]);
    }

    public function test_search_validates_and_limits_to_ten(): void
    {
        $api = $this->api();
        $api->shouldReceive('search')->with('band', 'track', ['limit' => 10])->once()->andReturn(['tracks' => ['items' => [
            ['id' => self::TRK, 'name' => 'Song', 'artists' => [['name' => 'Band']], 'album' => ['name' => 'LP', 'release_date' => '2001', 'images' => []]],
        ]]]);

        $this->getJson('/api/library/external/spotify/search?q=b&type=track')->assertStatus(422);
        $this->getJson('/api/library/external/spotify/search?q=band&type=playlist')->assertStatus(422);
        $this->getJson('/api/library/external/spotify/search?q=band&type=track&limit=50')->assertStatus(422);
        $track = $this->getJson('/api/library/external/spotify/search?q=band&type=track&limit=10')->assertOk()->json('data.0');
        $this->assertSame(['Song', 'Band', '2001'], [$track['title'], $track['artist'], $track['year']]);
    }

    public function test_artist_and_album_browse(): void
    {
        $api = $this->api();
        $api->shouldReceive('getArtist')->with(self::ART)->andReturn(['id' => self::ART, 'name' => 'Band', 'images' => []]);
        $api->shouldReceive('getArtistAlbums')->andReturn(['items' => [$this->spotifyAlbum(self::ALB, 'First'), $this->spotifyAlbum('album000000CD', 'Second', '2005')], 'next' => null]);
        $api->shouldReceive('getAlbum')->with(self::ALB)->andReturn($this->spotifyAlbum(self::ALB, 'First'));
        $api->shouldReceive('getAlbumTracks')->andReturn(['items' => [['id' => self::TRK, 'name' => 'Song', 'track_number' => 1, 'disc_number' => 1, 'duration_ms' => 200000, 'artists' => [['name' => 'Band']]]], 'next' => null]);

        $artist = $this->getJson('/api/library/external/spotify/artists/'.self::ART)->assertOk()->json();
        $this->assertSame('Band', $artist['artist']['title']);
        $this->assertSame(['Second', 'First'], array_column($artist['albums'], 'title'), 'newest first');

        $album = $this->getJson('/api/library/external/spotify/albums/'.self::ALB)->assertOk()->json();
        $this->assertSame('First', $album['album']['title']);
        $this->assertSame(['Song'], array_column($album['tracks'], 'title'));
        $this->assertSame('track', $album['tracks'][0]['type']);
    }

    public function test_unknown_provider_not_connected_client_and_service_failure(): void
    {
        $this->getJson('/api/library/external/nope/search?q=band&type=album')->assertNotFound()->assertJson(['error' => 'unknown_provider']);

        $api = $this->api();
        Client::create(['name' => 'Touch', 'type' => 'multi', 'status' => 'approved', 'api_token' => 'tok', 'registration_token' => 'reg']);
        $this->getJson('/api/library/external/spotify/search?q=band&type=album&client=tok')->assertForbidden();

        $api->shouldReceive('search')->andThrow(new \RuntimeException('boom'));
        $this->getJson('/api/library/external/spotify/search?q=band&type=album')->assertStatus(502)->assertJson(['error' => 'driver_error']);

        $this->disconnectSpotify();
        $this->getJson('/api/library/external/spotify/search?q=band&type=album')->assertStatus(422)->assertJson(['error' => 'not_connected']);
    }

    public function test_play_external_starts_the_album_artist_or_track_without_importing(): void
    {
        $api = $this->api();
        $device = Device::factory()->inState(State::Playing)->create(['device_driver' => \App\Integrations\Spotify\MusicPlayerDriver::class, 'device_driver_name' => 'Spotify']);
        $api->shouldReceive('play')->with('', ['context_uri' => 'spotify:album:'.self::ALB])->once();
        $api->shouldReceive('play')->with('', ['context_uri' => 'spotify:artist:'.self::ART])->once();
        $api->shouldReceive('play')->with('', ['uris' => ['spotify:track:'.self::TRK]])->once();
        $api->shouldReceive('shuffle')->andReturn(true);

        $this->postJson("/api/devices/{$device->id}/library/play-external", ['provider' => 'spotify', 'type' => 'album', 'id' => self::ALB])->assertOk()->assertJson(['status' => 'ok']);
        $this->postJson("/api/devices/{$device->id}/library/play-external", ['provider' => 'spotify', 'type' => 'artist', 'id' => self::ART, 'shuffle' => true])->assertOk();
        $this->postJson("/api/devices/{$device->id}/library/play-external", ['provider' => 'spotify', 'type' => 'track', 'id' => self::TRK])->assertOk();

        $this->assertSame(0, Track::count());
        $this->assertSame(0, Album::count(), 'playing does not import');
    }

    public function test_play_external_refuses_what_cannot_play(): void
    {
        $this->api();
        $spotify = Device::factory()->inState(State::Playing)->create(['device_driver' => \App\Integrations\Spotify\MusicPlayerDriver::class, 'device_driver_name' => 'Spotify']);
        $plain = Device::factory()->inState(State::Playing)->create();

        $this->postJson("/api/devices/{$plain->id}/library/play-external", ['provider' => 'spotify', 'type' => 'album', 'id' => self::ALB])->assertStatus(422)->assertJson(['error' => 'not_playable']);
        $this->postJson("/api/devices/{$spotify->id}/library/play-external", ['provider' => 'deezer', 'type' => 'album', 'id' => self::ALB])->assertStatus(422)->assertJson(['error' => 'not_playable']);
        $this->postJson("/api/devices/{$spotify->id}/library/play-external", ['provider' => 'spotify', 'type' => 'album', 'id' => 'x'])->assertStatus(422);
    }

    public function test_mcp_external_tool(): void
    {
        Queue::fake();
        $api = $this->api();
        $api->shouldReceive('search')->andReturn(['artists' => ['items' => [['id' => self::ART, 'name' => 'Band', 'images' => []]]]]);

        ReMomentServer::tool(Tools\External::class, ['action' => 'search', 'q' => 'band', 'type' => 'artist'])->assertOk()->assertSee('Band');
        ReMomentServer::tool(Tools\External::class, ['action' => 'search', 'q' => 'band'])->assertHasErrors(['search needs type']);
        ReMomentServer::tool(Tools\External::class, ['action' => 'play', 'type' => 'album', 'id' => self::ALB])->assertHasErrors(['play needs']);
    }
}
