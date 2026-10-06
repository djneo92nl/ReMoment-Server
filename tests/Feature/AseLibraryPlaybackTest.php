<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\BangOlufsen\Ase\MusicPlayerDriver;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAseConnector;
use Tests\TestCase;

/** The ASE music players (Essence, M3/M5, Moment) play DLNA library tracks like the V1's video driver does. */
class AseLibraryPlaybackTest extends TestCase
{
    use RefreshDatabase;

    private function driver(?FakeAseConnector &$api = null): MusicPlayerDriver
    {
        $device = Device::create([
            'ip_address' => '10.0.0.20',
            'device_name' => 'Essence',
            'device_brand_name' => 'Bang & Olufsen',
            'device_product_type' => 'BeoSound Essence',
            'device_driver' => MusicPlayerDriver::class,
            'device_driver_name' => 'ASE',
        ]);
        DeviceCache::updateState($device->id, State::Standby);

        $driver = $device->driver;
        $api = new FakeAseConnector;
        $driver->deviceApi = $api;

        return $driver;
    }

    private function track(string $name, ?string $url = 'http://dlna.test/a.mp3'): Track
    {
        $artist = Artist::firstOrCreate(['name' => 'Artist']);
        $album = Album::firstOrCreate(['name' => 'Album', 'artist_id' => $artist->id]);
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'external_id' => uniqid('dlna:', true), 'name' => $name, 'source' => 'dlna']);

        if ($url) {
            Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'dlna_url', 'value' => $url, 'type' => 'url', 'source' => 'dlna:1']);
        }

        return $track;
    }

    public function test_the_capability_is_reported_for_an_ase_music_player(): void
    {
        $driver = $this->driver();

        $this->getJson("/api/devices/{$driver->device->id}")->assertJsonPath('data.capabilities', fn ($caps) => in_array('library_playback', $caps, true));
    }

    public function test_plays_a_track_through_the_play_queue(): void
    {
        $driver = $this->driver($api);

        $driver->playLibraryTrack($this->track('One', 'http://dlna.test/one.mp3'));

        $this->assertSame(['POST BeoZone/Zone/PlayQueue?instantplay'], $api->requests);
        $this->assertSame(['playQueueItem' => ['behaviour' => 'impulsive', 'track' => ['dlna' => ['url' => 'http://dlna.test/one.mp3']]]], $api->bodies[0]);
    }

    public function test_a_track_without_a_stream_is_refused(): void
    {
        $driver = $this->driver($api);

        $this->expectExceptionMessage('has no DLNA URL');

        try {
            $driver->playLibraryTrack($this->track('Two', null));
        } finally {
            $this->assertSame([], $api->requests);
        }
    }

    public function test_plays_a_collection_as_a_queue_starting_instantly(): void
    {
        $driver = $this->driver($api);
        $tracks = collect([$this->track('A', 'http://dlna.test/a.mp3'), $this->track('B', null), $this->track('C', 'http://dlna.test/c.mp3')]);

        $driver->playLibraryTracks($tracks);

        $this->assertSame(['POST BeoZone/Zone/PlayQueue?instantplay', 'POST BeoZone/Zone/PlayQueue'], $api->requests, 'the first starts playing, the rest are queued; a track without a stream is skipped');
    }

    public function test_plays_a_playlist(): void
    {
        $driver = $this->driver($api);
        $playlist = Playlist::create(['name' => 'Mix']);
        $playlist->tracks()->attach([$this->track('A', 'http://dlna.test/a.mp3')->id, $this->track('B', 'http://dlna.test/b.mp3')->id]);

        $driver->playLibraryPlaylist($playlist);

        $this->assertCount(2, $api->requests);
        $this->assertSame('http://dlna.test/a.mp3', $api->bodies[0]['playQueueItem']['track']['dlna']['url']);
    }
}
