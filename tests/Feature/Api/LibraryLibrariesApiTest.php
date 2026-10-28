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

    public function test_playable_is_absent_without_a_device_and_an_unknown_device_is_422(): void
    {
        $this->release('Dlna Band', 'dlna');

        $this->getJson('/api/library/libraries')->assertJsonMissingPath('data.0.playable');
        $this->getJson('/api/library/libraries?device_id=9999')->assertStatus(422)->assertJsonValidationErrors('device_id');
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

        Cache::flush();
        $this->release('Own Band', 'files');
        $ids = collect($this->getJson('/api/library/libraries')->json('data'))->pluck('id')->all();
        $this->assertSame(['files', 'dlna'], $ids, 'local first, then streaming, in registry order');
        $this->assertNotContains('spotify', LibrarySources::libraryIds(), 'Spotify extends the library live: it is not a library');
    }

    public function test_a_hidden_library_is_left_out_and_a_merged_track_counts_for_both(): void
    {
        $this->release('Own Band', 'files');
        $merged = $this->release('Dlna Band', 'dlna');
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $merged->id, 'key' => 'dlna_url', 'value' => 'http://x/1.mp3', 'type' => 'url', 'source' => 'files']);
        $client = $this->client(['dlna']);

        $own = collect($this->getJson('/api/library/libraries?client='.$client->api_token)->json('data'));
        $this->assertSame(['files'], $own->pluck('id')->all(), 'the hidden library is not listed');
        $this->assertSame(2, $own->firstWhere('id', 'files')['album_count'], 'the track available from both stays while one source is shown');
        $this->assertSame([], $this->getJson('/api/library/libraries?client='.$this->client(['dlna', 'files'])->api_token)->json('data'));
    }

    public function test_scope_accepts_a_library_id_and_the_legacy_values(): void
    {
        $this->release('Own Band', 'files');
        $this->release('Dlna Band', 'dlna');

        $this->assertSame(['Dlna Band LP'], collect($this->getJson('/api/library/albums?scope=dlna')->json('data'))->pluck('name')->all());
        $this->assertSame(['Own Band LP'], collect($this->getJson('/api/library/albums?scope=files')->json('data'))->pluck('name')->all());
        $this->assertSame(['Own Band LP'], collect($this->getJson('/api/library/albums?scope=local')->json('data'))->pluck('name')->all());
        $this->assertSame(['Dlna Band LP'], collect($this->getJson('/api/library/albums?scope=streaming')->json('data'))->pluck('name')->all());
        $this->assertCount(2, $this->getJson('/api/library/albums?scope=all')->json('data'));
        $this->getJson('/api/library/albums?scope=spotify')->assertStatus(422);
        $this->getJson('/api/library/albums?scope=nope')->assertStatus(422);
    }

    public function test_playable_per_library_for_a_device(): void
    {
        $this->release('Own Band', 'files');
        $capable = Device::factory()->create(['device_driver' => FakeLibraryPlaybackDriver::class]);
        $plain = Device::factory()->create(['device_driver' => FakeBareDriver::class]);

        $playable = fn (Device $d) => collect($this->getJson('/api/library/libraries?device_id='.$d->id)->json('data'))->pluck('playable', 'id')->all();

        $this->assertSame(['files' => true], $playable($capable));
        $this->assertSame(['files' => false], $playable($plain));
        $this->getJson('/api/library/libraries?device_id=9999')->assertStatus(422);
    }
}
