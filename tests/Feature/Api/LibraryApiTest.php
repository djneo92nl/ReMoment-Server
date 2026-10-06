<?php

namespace Tests\Feature\Api;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\Spotify\MusicPlayerDriver as SpotifyDriver;
use App\Jobs\ProcessArtwork;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\Play;
use App\Services\SpotifyTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\TestCase;

/** Contract tests for /api/library/* and playing albums/artists on a device (docs/api/library.md). */
class LibraryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Setting::set(\App\Domain\Library\LeadingSource::SETTING, 'all');

        FakeLibraryPlaybackDriver::reset();
    }

    private function artist(string $name): Artist
    {
        return Artist::create(['name' => $name, 'source' => 'dlna']);
    }

    private function album(Artist $artist, string $name, ?string $released = null, ?string $cover = null): Album
    {
        return Album::create([
            'artist_id' => $artist->id,
            'name' => $name,
            'source' => 'dlna',
            'released_at' => $released,
            'images' => $cover ? [['url' => $cover]] : null,
        ]);
    }

    private function track(Album $album, string $name, bool $dlna = true, ?string $spotifyId = null): Track
    {
        $track = Track::create([
            'album_id' => $album->id,
            'artist_id' => $album->artist_id,
            'external_id' => $spotifyId ? "spotify:track:{$spotifyId}" : uniqid('dlna:', true),
            'name' => $name,
            'duration' => 200,
            'source' => $spotifyId ? 'spotify' : 'dlna',
        ]);

        if ($dlna) {
            Metadata::create([
                'metadatable_type' => Track::class,
                'metadatable_id' => $track->id,
                'key' => 'dlna_url',
                'value' => "http://nas.test/{$track->id}.flac",
                'type' => 'url',
                'source' => 'dlna:1',
            ]);
        }

        return $track;
    }

    private function device(string $driver = FakeLibraryPlaybackDriver::class, State $state = State::Standby): Device
    {
        $device = Device::create([
            'ip_address' => '10.0.0.1',
            'device_name' => 'Living Room',
            'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker',
            'device_driver' => $driver,
            'device_driver_name' => 'Fake',
        ]);
        DeviceCache::updateState($device->id, $state);

        return $device;
    }

    private function processedCover(string $url): void
    {
        ArtworkCache::put($url, [
            'proxy_512' => '/storage/a/512.jpg', 'proxy_320' => '/storage/a/320.jpg', 'proxy_120' => '/storage/a/120.jpg',
            'proxy_bg' => '/storage/a/bg.jpg', 'proxy_bg_320x480' => '/storage/a/bg_320x480.jpg', 'colors' => [], 'safe_colors' => [],
        ]);
    }

    /** Spotify connected, with a mocked Web API client. */
    private function spotifyApi(): Mockery\MockInterface
    {
        $api = Mockery::mock(SpotifyWebAPI::class);
        $this->mock(SpotifyTokenService::class, function ($mock) use ($api) {
            $mock->shouldReceive('isConnected')->andReturn(true);
            $mock->shouldReceive('makeApiClient')->andReturn($api);
        });

        return $api;
    }

    // --- Browse ---

    public function test_artists_are_alphabetical_ignoring_the_and_only_with_albums(): void
    {
        $this->album($this->artist('The Beatles'), 'Abbey Road');
        $this->album($this->artist('ABBA'), 'Arrival');
        $this->album($this->artist('Coldplay'), 'Parachutes');
        $this->artist('No Albums');

        $this->getJson('/api/library/artists')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['ABBA', 'The Beatles', 'Coldplay'])
            ->assertJsonPath('next_cursor', null)
            ->assertJsonPath('data.0', [
                'id' => Artist::where('name', 'ABBA')->value('id'),
                'name' => 'ABBA',
                'album_count' => 1,
                'artwork' => null,
                'favorite' => false,
            ]);
    }

    public function test_artists_can_start_at_a_letter(): void
    {
        $this->album($this->artist('ABBA'), 'Arrival');
        $this->album($this->artist('The Beatles'), 'Abbey Road');
        $this->album($this->artist('Coldplay'), 'Parachutes');
        $this->album($this->artist('Muse'), 'Absolution');

        // "The Beatles" sorts under B
        $this->getJson('/api/library/artists?letter=B')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['The Beatles', 'Coldplay', 'Muse']);
        $this->getJson('/api/library/artists?letter=n')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/library/artists?letter=%23')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/library/artists?letter=AB')->assertUnprocessable();
    }

    public function test_artists_are_paged_by_an_opaque_cursor(): void
    {
        foreach (range(1, 52) as $i) {
            $this->album($this->artist(sprintf('Artist %02d', $i)), 'Album');
        }

        $first = $this->getJson('/api/library/artists')->assertJsonCount(50, 'data');
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        $this->getJson('/api/library/artists?cursor='.$cursor)
            ->assertJsonPath('data.*.name', ['Artist 51', 'Artist 52'])
            ->assertJsonPath('next_cursor', null);

        $this->getJson('/api/library/artists?cursor=nonsense')->assertStatus(422)->assertJsonValidationErrors('cursor');
    }

    public function test_artwork_is_the_small_object_or_null_and_queued_once(): void
    {
        Queue::fake();
        $artist = $this->artist('Muse');
        $this->album($artist, 'Absolution', cover: 'https://img.test/done.jpg');
        $this->processedCover('https://img.test/done.jpg');
        $pending = $this->album($this->artist('Other'), 'Pending', cover: 'https://img.test/new.jpg');

        $this->getJson('/api/library/artists')
            ->assertJsonPath('data.0.artwork', [
                'hash' => md5('https://img.test/done.jpg'),
                'proxy_120' => '/storage/a/120.jpg',
                'proxy_320' => '/storage/a/320.jpg',
            ]);

        $this->getJson("/api/library/albums/{$pending->id}")->assertJsonPath('artwork', null);
        $this->getJson("/api/library/albums/{$pending->id}")->assertJsonPath('artwork', null);

        Queue::assertPushed(ProcessArtwork::class, 1);
    }

    public function test_artist_detail_lists_albums_newest_year_first_then_name(): void
    {
        $artist = $this->artist('Radiohead');
        $this->album($artist, 'Pablo Honey', '1993-02-22');
        $this->album($artist, 'Kid A', '2000-10-02');
        $this->album($artist, 'Amnesiac', '2000-06-05');
        $this->album($artist, 'Unknown Year');

        $this->getJson("/api/library/artists/{$artist->id}")
            ->assertOk()
            ->assertJsonPath('name', 'Radiohead')
            ->assertJsonPath('favorite', false)
            ->assertJsonPath('albums.*.name', ['Amnesiac', 'Kid A', 'Pablo Honey', 'Unknown Year'])
            ->assertJsonPath('albums.0', [
                'id' => Album::where('name', 'Amnesiac')->value('id'),
                'name' => 'Amnesiac',
                'artist_name' => 'Radiohead',
                'year' => 2000,
                'artwork' => null,
                'favorite' => false,
            ]);
    }

    public function test_album_detail(): void
    {
        $artist = $this->artist('Muse');
        $album = $this->album($artist, 'Absolution', '2003-09-15');
        $one = $this->track($album, 'Intro');
        $two = $this->track($album, 'Apocalypse Please', dlna: false);

        $this->getJson("/api/library/albums/{$album->id}")
            ->assertOk()
            ->assertExactJson([
                'id' => $album->id,
                'name' => 'Absolution',
                'artist' => ['id' => $artist->id, 'name' => 'Muse'],
                'year' => 2003,
                'artwork' => null,
                'favorite' => false,
                'genres' => [],
                'details' => [
                    'label' => null, 'catalog_number' => null, 'release_type' => null, 'secondary_types' => [],
                    'release_date' => null, 'country' => null, 'track_count' => null, 'disc_count' => null,
                    'summary' => null, 'wikipedia_url' => null, 'format' => null, 'mood' => null, 'style' => null,
                    'theme' => null, 'rating' => null, 'tags' => [], 'credits' => [], 'discogs_url' => null,
                    'listeners' => null, 'playcount' => null,
                ],
                'playable' => true,
                'tracks' => [
                    ['id' => $one->id, 'name' => 'Intro', 'duration' => 200, 'playable' => true, 'details' => ['genres' => [], 'quality' => null, 'credits' => [], 'isrc' => null, 'explicit' => null, 'popularity' => null, 'listeners' => null, 'playcount' => null]],
                    ['id' => $two->id, 'name' => 'Apocalypse Please', 'duration' => 200, 'playable' => false, 'details' => ['genres' => [], 'quality' => null, 'credits' => [], 'isrc' => null, 'explicit' => null, 'popularity' => null, 'listeners' => null, 'playcount' => null]],
                ],
            ]);

        // Per device: a device without a library path can play nothing.
        $bare = $this->device(FakeBareDriver::class);
        $this->getJson("/api/library/albums/{$album->id}?device_id={$bare->id}")
            ->assertJsonPath('playable', false)
            ->assertJsonPath('tracks.0.playable', false);
    }

    public function test_recent_lists_distinct_albums_by_last_play(): void
    {
        $device = $this->device();
        $artist = $this->artist('Muse');
        $old = $this->album($artist, 'Old');
        $new = $this->album($artist, 'New');
        $this->album($artist, 'Never Played');
        foreach ([[$old, 3], [$new, 2], [$old, 1]] as [$album, $daysAgo]) {
            Play::create(['device_id' => $device->id, 'track_id' => $this->track($album, 'T')->id, 'source_type' => 'dlna', 'played_at' => now()->subDays($daysAgo)]);
        }

        $this->getJson('/api/library/recent')->assertJsonPath('data.*.name', ['Old', 'New']);
        $this->getJson('/api/library/recent?limit=1')->assertJsonPath('data.*.name', ['Old']);
        $this->getJson('/api/library/recent?limit=101')->assertStatus(422);
    }

    // --- Search ---

    public function test_search_finds_artists_albums_and_tracks_by_name(): void
    {
        $muse = $this->artist('Muse');
        $absolution = $this->album($muse, 'Absolution');
        $this->track($absolution, 'Hysteria');
        $this->track($absolution, 'Apocalypse Please');
        $other = $this->album($this->artist('Abba'), 'Gold');
        $this->track($other, 'Waterloo');

        $this->getJson('/api/library/search?q=muse')
            ->assertOk()
            ->assertJsonPath('artists.*.name', ['Muse'])
            ->assertJsonPath('albums', [])
            ->assertJsonPath('tracks', []);

        $this->getJson('/api/library/search?q=ABSOL')
            ->assertOk()
            ->assertJsonPath('albums.*.name', ['Absolution'])
            ->assertJsonPath('albums.0.artist_name', 'Muse');

        $this->getJson('/api/library/search?q=hyster')
            ->assertOk()
            ->assertJsonPath('tracks.*.name', ['Hysteria'])
            ->assertJsonPath('tracks.0.album_id', $absolution->id)
            ->assertJsonPath('tracks.0.artist_name', 'Muse');
    }

    public function test_search_needs_two_characters_and_escapes_wildcards(): void
    {
        $this->album($this->artist('Muse'), 'Absolution');

        $this->getJson('/api/library/search?q=a')->assertStatus(422);
        $this->getJson('/api/library/search')->assertStatus(422);
        $this->getJson('/api/library/search?q=%25%25')->assertOk()->assertJsonPath('albums', []);
    }

    // --- Favorites ---

    public function test_favorites_can_be_set_and_listed_newest_first(): void
    {
        $artist = $this->artist('Muse');
        $first = $this->album($artist, 'First');
        $second = $this->album($artist, 'Second');

        $this->travel(-2)->minutes();
        $this->putJson("/api/library/albums/{$first->id}/favorite", ['favorite' => true])->assertExactJson(['favorite' => true]);
        $this->travelBack();
        $this->putJson("/api/library/albums/{$second->id}/favorite", ['favorite' => true])->assertOk();
        // Favoriting again keeps the original time.
        $this->putJson("/api/library/albums/{$first->id}/favorite", ['favorite' => true])->assertOk();
        $this->putJson("/api/library/artists/{$artist->id}/favorite", ['favorite' => true])->assertExactJson(['favorite' => true]);

        $this->getJson('/api/library/favorites')
            ->assertOk()
            ->assertJsonPath('albums.*.name', ['Second', 'First'])
            ->assertJsonPath('albums.0.favorite', true)
            ->assertJsonPath('artists.0.name', 'Muse')
            ->assertJsonPath('artists.0.album_count', 2)
            ->assertJsonPath('artists.0.favorite', true);

        $this->putJson("/api/library/albums/{$second->id}/favorite", ['favorite' => false])->assertExactJson(['favorite' => false]);
        $this->getJson('/api/library/favorites')->assertJsonPath('albums.*.name', ['First']);
        $this->getJson("/api/library/albums/{$first->id}")->assertJsonPath('favorite', true);
        $this->putJson("/api/library/albums/{$first->id}/favorite", [])->assertStatus(422);
    }

    // --- Play on a device: DLNA ---

    public function test_play_album_over_dlna_from_a_start_track(): void
    {
        $device = $this->device();
        $album = $this->album($this->artist('Muse'), 'Absolution');
        $this->track($album, 'One');
        $two = $this->track($album, 'Two');
        $this->track($album, 'Three');

        $this->postJson("/api/devices/{$device->id}/library/play-album", ['album_id' => $album->id, 'start_track_id' => $two->id])
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'album' => 'Absolution']);

        $this->assertSame(['Two', 'Three'], FakeLibraryPlaybackDriver::$calls[0]['tracks']->pluck('name')->all());
    }

    public function test_play_album_shuffled_and_play_artist(): void
    {
        $device = $this->device();
        $artist = $this->artist('Muse');
        $album = $this->album($artist, 'Absolution');
        foreach (['One', 'Two', 'Three'] as $name) {
            $this->track($album, $name);
        }

        $this->postJson("/api/devices/{$device->id}/library/play-album", ['album_id' => $album->id, 'shuffle' => true])->assertOk();
        $this->assertEqualsCanonicalizing(['One', 'Two', 'Three'], FakeLibraryPlaybackDriver::$calls[0]['tracks']->pluck('name')->all());

        $this->postJson("/api/devices/{$device->id}/library/play-artist", ['artist_id' => $artist->id, 'shuffle' => false])
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'artist' => 'Muse']);
        $this->assertSame(['One', 'Two', 'Three'], FakeLibraryPlaybackDriver::$calls[1]['tracks']->pluck('name')->all());
    }

    public function test_play_album_errors(): void
    {
        $album = $this->album($this->artist('Muse'), 'No Streams');
        $this->track($album, 'Nothing', dlna: false);
        $other = $this->track($this->album($this->artist('X'), 'Other'), 'Elsewhere');

        $device = $this->device();
        $this->postJson("/api/devices/{$device->id}/library/play-album", ['album_id' => $album->id])
            ->assertStatus(422)->assertJsonPath('error', 'not_playable');
        $this->postJson("/api/devices/{$device->id}/library/play-album", ['album_id' => $album->id, 'start_track_id' => $other->id])
            ->assertStatus(422)->assertJsonValidationErrors('start_track_id');

        $bare = $this->device(FakeBareDriver::class);
        $this->postJson("/api/devices/{$bare->id}/library/play-artist", ['artist_id' => $album->artist_id])
            ->assertStatus(422)->assertJsonPath('error', 'not_playable');

        $gone = $this->device(state: State::Unreachable);
        $this->postJson("/api/devices/{$gone->id}/library/play-album", ['album_id' => $album->id])
            ->assertStatus(503)->assertJsonPath('error', 'unreachable');
    }

    // --- Play on a device: Spotify ---

    public function test_play_album_on_a_mapped_speaker_through_spotify(): void
    {
        $api = $this->spotifyApi();
        $device = $this->device(FakeBareDriver::class);
        DeviceMeta::create(['device_id' => $device->id, 'key' => 'spotify_connect_name', 'value' => 'Living Room Speaker']);
        $album = $this->album($this->artist('Muse'), 'Absolution');
        $this->track($album, 'One', dlna: false, spotifyId: 'one');
        $this->track($album, 'Two', dlna: false, spotifyId: 'two');

        $this->getJson("/api/devices/{$device->id}")->assertJsonPath('data.capabilities', ['library_playback']);

        $api->shouldReceive('getMyDevices')->andReturn(['devices' => [['id' => 'connect-1', 'name' => 'Living Room Speaker']]]);
        $api->shouldReceive('getTrack')->once()->with('spotify:track:one')->andReturn(['album' => ['uri' => 'spotify:album:xyz']]);
        $api->shouldReceive('play')->twice()->with('connect-1', Mockery::on(fn ($o) => $o['context_uri'] === 'spotify:album:xyz'))->andReturn(true);
        $api->shouldReceive('shuffle')->with(['state' => false, 'device_id' => 'connect-1'])->andReturn(true);

        $this->postJson("/api/devices/{$device->id}/library/play-album", ['album_id' => $album->id])
            ->assertOk()->assertJsonPath('album', 'Absolution');

        // The album URI is stored, so it is looked up once.
        $this->postJson("/api/devices/{$device->id}/library/play-album", ['album_id' => $album->id])->assertOk();
        $this->assertSame('spotify:album:xyz', $album->metadata()->where('key', 'spotify_album_uri')->value('value'));
    }

    public function test_play_artist_on_the_spotify_device_plays_the_track_uris(): void
    {
        $api = $this->spotifyApi();
        $spotify = $this->device(SpotifyDriver::class);
        $artist = $this->artist('Muse');
        $this->track($this->album($artist, 'A'), 'One', dlna: false, spotifyId: 'one');
        $this->track($this->album($artist, 'B'), 'Two', dlna: false, spotifyId: 'two');

        $api->shouldReceive('play')->once()->with('', ['uris' => ['spotify:track:one', 'spotify:track:two']])->andReturn(true);
        $api->shouldReceive('shuffle')->with(['state' => false])->andReturn(true);

        $this->postJson("/api/devices/{$spotify->id}/library/play-artist", ['artist_id' => $artist->id])->assertOk();
    }

    public function test_spotify_errors_are_502_and_a_missing_connect_device_too(): void
    {
        $api = $this->spotifyApi();
        $device = $this->device(FakeBareDriver::class);
        DeviceMeta::create(['device_id' => $device->id, 'key' => 'spotify_connect_name', 'value' => 'Living Room Speaker']);
        $album = $this->album($this->artist('Muse'), 'Absolution');
        $this->track($album, 'One', dlna: false, spotifyId: 'one');

        $api->shouldReceive('getMyDevices')->andReturn(['devices' => []]);

        $this->postJson("/api/devices/{$device->id}/library/play-album", ['album_id' => $album->id])
            ->assertStatus(502)
            ->assertJsonPath('error', 'driver_error');
    }

    public function test_a_mapped_speaker_has_no_library_playback_without_spotify(): void
    {
        $device = $this->device(FakeBareDriver::class);
        DeviceMeta::create(['device_id' => $device->id, 'key' => 'spotify_connect_name', 'value' => 'Living Room Speaker']);

        $this->getJson("/api/devices/{$device->id}")->assertJsonPath('data.capabilities', []);
    }

    public function test_the_web_pages_toggle_favorites(): void
    {
        $artist = $this->artist('Muse');
        $album = $this->album($artist, 'Absolution');

        $this->post("/albums/{$album->id}/favorite")->assertRedirect();
        $this->post("/artists/{$artist->id}/favorite")->assertRedirect();
        $this->assertNotNull($album->fresh()->favorited_at);
        $this->assertNotNull($artist->fresh()->favorited_at);

        $this->post("/albums/{$album->id}/favorite");
        $this->assertNull($album->fresh()->favorited_at);
        $this->get("/albums/{$album->id}")->assertOk()->assertSee('Add to favorites');
    }
}
