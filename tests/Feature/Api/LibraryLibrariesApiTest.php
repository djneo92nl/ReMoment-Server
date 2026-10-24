<?php

namespace Tests\Feature\Api;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibrarySources;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\RadioStation;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CachesArtwork;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\FakeRadioDriver;
use Tests\Support\FakesSpotify;
use Tests\TestCase;

/** GET /api/library/libraries, per-library scope and GET /api/library/recent-items (docs/api/library.md). */
class LibraryLibrariesApiTest extends TestCase
{
    use CachesArtwork;
    use FakesSpotify;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set(LeadingSource::SETTING, 'dlna');
    }

    private function client(array $hidden): Client
    {
        return Client::create([
            'name' => 'Kitchen', 'type' => 'multi', 'status' => 'approved',
            'registration_token' => Client::generateToken(), 'api_token' => Client::generateToken(), 'hidden_sources' => $hidden,
        ]);
    }

    private function release(string $name, string $source, ?string $cover = null): Track
    {
        $artist = Artist::create(['name' => $name, 'source' => $source]);
        $album = Album::create([
            'artist_id' => $artist->id, 'name' => "{$name} LP", 'source' => $source,
            'images' => $cover ? [['url' => $cover]] : null,
        ]);

        return Track::create([
            'album_id' => $album->id, 'artist_id' => $artist->id, 'name' => "{$name} song",
            'external_id' => "{$source}:{$name}", 'source' => $source,
        ]);
    }

    private function play(Track $track, string $at): void
    {
        Play::create(['device_id' => Device::factory()->create()->id, 'track_id' => $track->id, 'source_type' => 'library', 'played_at' => $at]);
    }

    public function test_lists_only_libraries_with_content(): void
    {
        $this->getJson('/api/library/libraries')->assertOk()->assertExactJson(['data' => []]);

        // The counts are cached for a few minutes, so a change shows up after the cache has expired.
        Cache::flush();
        $this->release('Dlna Band', 'dlna');
        $this->getJson('/api/library/libraries')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', ['id' => 'dlna', 'name' => 'DLNA', 'kind' => 'streaming', 'album_count' => 1, 'artist_count' => 1, 'playlist_count' => 0, 'artwork' => null]);

        $spot = $this->release('Spot Band', 'spotify');
        $playlist = Playlist::create(['name' => 'Mix', 'source' => 'spotify']);
        $playlist->tracks()->sync([$spot->id => ['position' => 0]]);

        Cache::flush();
        $this->getJson('/api/library/libraries')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.id', 'spotify')
            ->assertJsonPath('data.1.kind', 'streaming')
            ->assertJsonPath('data.1.playlist_count', 1)
            ->assertJsonPath('data.0.playlist_count', 0);
    }

    public function test_hidden_libraries_are_left_out_and_a_merged_track_counts_for_both(): void
    {
        $both = $this->release('Both Band', 'dlna');
        Metadata::create([
            'metadatable_type' => Track::class, 'metadatable_id' => $both->id,
            'key' => 'external_id', 'value' => 'spotify:track:abc', 'type' => 'string', 'source' => 'spotify',
        ]);
        $this->release('Spot Band', 'spotify');

        $response = $this->getJson('/api/library/libraries')->assertJsonCount(2, 'data');
        $this->assertSame(1, $response->json('data.0.album_count'));
        $this->assertSame(2, $response->json('data.1.album_count'));

        $this->getJson('/api/library/libraries?client='.$this->client(['spotify'])->api_token)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'dlna');
        $this->getJson('/api/library/libraries?client='.$this->client(['radio'])->api_token)
            ->assertJsonCount(2, 'data');
        $this->getJson('/api/library/libraries?client=nope')->assertNotFound();
    }

    public function test_artwork_is_the_most_recently_played_album_cover_of_the_library(): void
    {
        $this->release('Old', 'dlna', 'https://img.test/old.jpg');
        $played = $this->release('Played', 'dlna', 'https://img.test/played.jpg');
        $this->play($played, '2026-10-01 10:00:00');
        $this->cacheProcessedArtwork('https://img.test/played.jpg', ['hash' => md5('https://img.test/played.jpg')]);
        $this->cacheProcessedArtwork('https://img.test/old.jpg', ['hash' => md5('https://img.test/old.jpg')]);

        $this->getJson('/api/library/libraries')
            ->assertJsonPath('data.0.artwork.hash', md5('https://img.test/played.jpg'));
    }

    public function test_scope_accepts_a_library_id_and_keeps_the_legacy_values(): void
    {
        $this->release('Own Band', 'files');
        $this->release('Dlna Band', 'dlna');
        $this->release('Spot Band', 'spotify');

        $names = fn (string $scope) => collect($this->getJson("/api/library/artists?scope={$scope}")->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Own Band'], $names('files'));
        $this->assertSame(['Dlna Band'], $names('dlna'));
        $this->assertSame(['Spot Band'], $names('spotify'));
        $this->assertSame(['Own Band'], $names('local'));
        $this->assertEqualsCanonicalizing(['Dlna Band', 'Spot Band'], $names('streaming'));
        $this->assertCount(3, $names('all'));
        $this->getJson('/api/library/artists?scope=deezer')->assertStatus(422);
        $this->getJson('/api/library/albums?scope=spotify')->assertJsonCount(1, 'data');
        $this->getJson('/api/library/recent?scope=spotify')->assertOk();
        $this->getJson('/api/library/genres?scope=spotify')->assertOk();
        $this->getJson('/api/library/favorites?scope=spotify')->assertOk();
    }

    public function test_playlists_follow_the_scope_and_all_lists_every_library(): void
    {
        Setting::set(LeadingSource::SETTING, 'files');
        $dlna = $this->release('Own Band', 'files');
        $spot = $this->release('Spot Band', 'spotify');
        foreach ([['Local mix', $dlna], ['Spotify mix', $spot]] as [$name, $track]) {
            Playlist::create(['name' => $name, 'source' => 'local'])->tracks()->sync([$track->id => ['position' => 0]]);
        }
        Playlist::where('name', 'Spotify mix')->update(['last_played_at' => '2026-10-02 10:00:00']);

        // Without scope: the leading library (files here).
        $this->getJson('/api/library/playlists')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Local mix');
        $this->getJson('/api/library/playlists?scope=spotify')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Spotify mix')
            ->assertJsonPath('data.0.last_played_at', '2026-10-02T10:00:00+00:00');
        $this->getJson('/api/library/playlists?scope=all')->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Spotify mix')
            ->assertJsonPath('data.1.last_played_at', null);
    }

    public function test_recent_items_mix_albums_playlists_and_radio_newest_first(): void
    {
        $dlna = $this->release('Dlna Band', 'dlna');
        $spot = $this->release('Spot Band', 'spotify');
        $this->play($dlna, '2026-10-10 10:00:00');
        $this->play($spot, '2026-10-08 10:00:00');

        $playlist = Playlist::create(['name' => 'Mix', 'source' => 'spotify', 'last_played_at' => '2026-10-09 10:00:00']);
        $playlist->tracks()->sync([$spot->id => ['position' => 0]]);
        Playlist::create(['name' => 'Never', 'source' => 'spotify'])->tracks()->sync([$spot->id => ['position' => 0]]);

        $station = RadioStation::create(['name' => 'Alpha', 'last_played_at' => '2026-10-11 10:00:00']);
        RadioStation::create(['name' => 'Unplayed']);

        $response = $this->getJson('/api/library/recent-items')->assertOk()->assertJsonCount(4, 'data');
        $this->assertSame(['radio', 'album', 'playlist', 'album'], $response->json('data.*.type'));
        $this->assertSame(['radio', 'dlna', 'spotify', 'spotify'], $response->json('data.*.library'));
        $response->assertJsonPath('data.0.station.id', $station->id)
            ->assertJsonPath('data.0.album', null)
            ->assertJsonPath('data.0.playlist', null)
            ->assertJsonPath('data.1.album.name', 'Dlna Band LP')
            ->assertJsonPath('data.1.station', null)
            ->assertJsonPath('data.2.playlist.name', 'Mix')
            ->assertJsonPath('data.2.playlist.last_played_at', '2026-10-09T10:00:00+00:00')
            ->assertJsonPath('data.1.played_at', '2026-10-10T10:00:00+00:00');

        $this->getJson('/api/library/recent-items?limit=2')->assertJsonCount(2, 'data');
        $this->getJson('/api/library/recent-items?limit=0')->assertStatus(422);
    }

    public function test_recent_items_library_is_where_it_was_played_from_else_the_leading_library(): void
    {
        $track = $this->release('Both Band', 'dlna');
        Metadata::create([
            'metadatable_type' => Track::class, 'metadatable_id' => $track->id,
            'key' => 'external_id', 'value' => 'spotify:track:abc', 'type' => 'string', 'source' => 'spotify',
        ]);
        $other = Track::create([
            'album_id' => $track->album_id, 'artist_id' => $track->artist_id, 'name' => 'Spotify only',
            'external_id' => 'spotify:track:def', 'source' => 'spotify',
        ]);

        // Played from Spotify, available from both: Spotify.
        $this->play($other, '2026-10-10 10:00:00');
        $this->getJson('/api/library/recent-items')->assertJsonPath('data.0.library', 'spotify');

        // The client hides Spotify: falls back to the leading library among what it still sees.
        $this->getJson('/api/library/recent-items?client='.$this->client(['spotify'])->api_token)
            ->assertJsonPath('data.0.library', 'dlna');
    }

    public function test_recent_items_hide_radio_for_a_client_hiding_it(): void
    {
        RadioStation::create(['name' => 'Alpha', 'last_played_at' => now()]);

        $this->getJson('/api/library/recent-items')->assertJsonCount(1, 'data');
        $this->getJson('/api/library/recent-items?client='.$this->client(['radio'])->api_token)->assertJsonCount(0, 'data');
        $this->getJson('/api/library/recent-items?client='.$this->client(['spotify'])->api_token)->assertJsonCount(1, 'data');
    }

    public function test_playing_a_station_records_when(): void
    {
        $device = Device::factory()->create(['ip_address' => '10.0.0.1', 'device_driver' => FakeRadioDriver::class]);
        $station = RadioStation::create(['name' => 'Alpha']);
        $station->setMeta('fakeradio', 'x');
        \App\Domain\Device\DeviceCache::updateState($device->id, \App\Domain\Device\State::Standby);

        $this->assertNull($station->fresh()->last_played_at);
        $this->postJson("/api/devices/{$device->id}/radio/{$station->id}")->assertOk();
        $this->assertNotNull($station->fresh()->last_played_at);
    }

    public function test_a_new_library_is_one_registry_entry(): void
    {
        $this->assertSame(['files', 'dlna', 'spotify'], LibrarySources::libraryIds());
        $this->assertSame(['files', 'dlna', 'spotify', 'radio'], array_keys(LibrarySources::all()));
        $this->assertSame(['files', 'spotify'], LeadingSource::hidden('dlna'));
    }

    /** @return array<string, bool> library id => playable */
    private function playableOn(Device $device): array
    {
        $data = $this->getJson("/api/library/libraries?device_id={$device->id}")->assertOk()->json('data');

        return array_column($data, 'playable', 'id');
    }

    private function mappedDevice(string $driver): Device
    {
        $device = Device::factory()->create(['ip_address' => '10.0.0.1', 'device_driver' => $driver]);
        DeviceMeta::create(['device_id' => $device->id, 'key' => 'spotify_connect_name', 'value' => 'Speaker']);

        return $device;
    }

    public function test_playable_per_library_for_a_device(): void
    {
        $this->release('Dlna Band', 'dlna');
        $this->release('Spot Band', 'spotify');

        $dlnaOnly = Device::factory()->create(['ip_address' => '10.0.0.1', 'device_driver' => FakeLibraryPlaybackDriver::class]);
        $neither = Device::factory()->create(['ip_address' => '10.0.0.2', 'device_driver' => FakeBareDriver::class]);

        // Spotify not connected: a mapped device still cannot play it.
        $this->disconnectSpotify();
        $this->assertSame(['dlna' => true, 'spotify' => false], $this->playableOn($dlnaOnly));
        $this->assertSame(['dlna' => false, 'spotify' => false], $this->playableOn($this->mappedDevice(FakeBareDriver::class)));

        $this->connectSpotify();
        $this->assertSame(['dlna' => true, 'spotify' => false], $this->playableOn($dlnaOnly));
        $this->assertSame(['dlna' => false, 'spotify' => false], $this->playableOn($neither));
        $this->assertSame(['dlna' => false, 'spotify' => true], $this->playableOn($this->mappedDevice(FakeBareDriver::class)));
        $this->assertSame(['dlna' => true, 'spotify' => true], $this->playableOn($this->mappedDevice(FakeLibraryPlaybackDriver::class)));
    }

    public function test_playable_is_absent_without_a_device_and_an_unknown_device_is_422(): void
    {
        $this->release('Dlna Band', 'dlna');

        $this->getJson('/api/library/libraries')->assertJsonMissingPath('data.0.playable');
        $this->getJson('/api/library/libraries?device_id=9999')->assertStatus(422)->assertJsonValidationErrors('device_id');
    }
}
