<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\TestCase;

class LibraryPlaybackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeLibraryPlaybackDriver::reset();
    }

    private function makeDevice(): Device
    {
        return Device::factory()->create([
            'ip_address' => '10.0.0.1',
            'device_name' => 'Test Speaker',
            'device_driver' => FakeLibraryPlaybackDriver::class,
        ]);
    }

    private function makeIncapableDevice(): Device
    {
        return Device::factory()->create([
            'ip_address' => '10.0.0.2',
            'device_name' => 'Dumb Speaker',
            'device_driver' => \App\Integrations\Spotify\MusicPlayerDriver::class,
            'device_driver_name' => 'Spotify',
        ]);
    }

    private function makeTrackWithDlnaUrl(Album $album, string $name = 'Track'): Track
    {
        $track = Track::create([
            'album_id' => $album->id,
            'artist_id' => $album->artist_id,
            'external_id' => uniqid('dlna:', true),
            'name' => $name,
            'source' => 'dlna',
        ]);

        Metadata::create([
            'metadatable_type' => Track::class,
            'metadatable_id' => $track->id,
            'key' => 'dlna_url',
            'value' => "http://example.test/{$track->id}.mp3",
            'type' => 'url',
            'source' => 'dlna:1',
        ]);

        return $track;
    }

    private function makeTrackWithoutDlnaUrl(Album $album, string $name = 'No URL Track'): Track
    {
        return Track::create([
            'album_id' => $album->id,
            'artist_id' => $album->artist_id,
            'external_id' => uniqid('spotify:', true),
            'name' => $name,
            'source' => 'spotify',
        ]);
    }

    public function test_library_capable_only_returns_devices_implementing_the_interface(): void
    {
        $capable = $this->makeDevice();
        $this->makeIncapableDevice();

        $result = Device::libraryCapable();

        $this->assertCount(1, $result);
        $this->assertSame($capable->id, $result->first()->id);
    }

    public function test_album_play_queues_all_playable_tracks(): void
    {
        $device = $this->makeDevice();
        $artist = Artist::create(['name' => 'Test Artist', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Test Album', 'source' => 'dlna']);
        $this->makeTrackWithDlnaUrl($album, 'Track One');
        $this->makeTrackWithDlnaUrl($album, 'Track Two');

        $response = $this->post(route('albums.play', [$album, $device]));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertCount(1, FakeLibraryPlaybackDriver::$calls);
        $this->assertCount(2, FakeLibraryPlaybackDriver::$calls[0]['tracks']);
    }

    public function test_album_play_reports_partial_playback_when_some_tracks_are_unplayable(): void
    {
        $device = $this->makeDevice();
        $artist = Artist::create(['name' => 'Test Artist', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Test Album', 'source' => 'dlna']);
        $this->makeTrackWithDlnaUrl($album);
        $this->makeTrackWithoutDlnaUrl($album);

        $response = $this->post(route('albums.play', [$album, $device]));

        $response->assertSessionHas('success', function ($message) {
            return str_contains($message, '1 of 2 tracks');
        });
        $this->assertCount(1, FakeLibraryPlaybackDriver::$calls[0]['tracks']);
    }

    public function test_album_play_fails_gracefully_when_no_tracks_are_playable(): void
    {
        $device = $this->makeDevice();
        $artist = Artist::create(['name' => 'Test Artist', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Test Album', 'source' => 'dlna']);
        $this->makeTrackWithoutDlnaUrl($album);

        $response = $this->post(route('albums.play', [$album, $device]));

        $response->assertSessionHas('error');
        $this->assertCount(0, FakeLibraryPlaybackDriver::$calls);
    }

    public function test_album_play_rejects_devices_without_library_playback(): void
    {
        $device = $this->makeIncapableDevice();
        $artist = Artist::create(['name' => 'Test Artist', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Test Album', 'source' => 'dlna']);
        $this->makeTrackWithDlnaUrl($album);

        $response = $this->post(route('albums.play', [$album, $device]));

        $response->assertSessionHas('error');
        $this->assertCount(0, FakeLibraryPlaybackDriver::$calls);
    }

    public function test_artist_play_queues_tracks_across_all_of_their_albums(): void
    {
        $device = $this->makeDevice();
        $artist = Artist::create(['name' => 'Test Artist', 'source' => 'dlna']);
        $albumOne = Album::create(['artist_id' => $artist->id, 'name' => 'Album One', 'source' => 'dlna']);
        $albumTwo = Album::create(['artist_id' => $artist->id, 'name' => 'Album Two', 'source' => 'dlna']);
        $this->makeTrackWithDlnaUrl($albumOne);
        $this->makeTrackWithDlnaUrl($albumTwo);

        $response = $this->post(route('artists.play', [$artist, $device]));

        $response->assertSessionHas('success');
        $this->assertCount(2, FakeLibraryPlaybackDriver::$calls[0]['tracks']);
    }

    public function test_artist_play_with_shuffle_still_queues_all_playable_tracks(): void
    {
        $device = $this->makeDevice();
        $artist = Artist::create(['name' => 'Test Artist', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Album', 'source' => 'dlna']);
        $this->makeTrackWithDlnaUrl($album, 'A');
        $this->makeTrackWithDlnaUrl($album, 'B');
        $this->makeTrackWithDlnaUrl($album, 'C');

        $response = $this->post(route('artists.play', [$artist, $device], absolute: false).'?shuffle=1');

        $response->assertSessionHas('success');
        $this->assertCount(3, FakeLibraryPlaybackDriver::$calls[0]['tracks']);
    }

    public function test_track_play_via_device_controller(): void
    {
        $device = $this->makeDevice();
        $artist = Artist::create(['name' => 'Test Artist', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Album', 'source' => 'dlna']);
        $track = $this->makeTrackWithDlnaUrl($album);

        $response = $this->post(route('tracks.play', [$track, $device]));

        $response->assertSessionHas('success');
        $this->assertCount(1, FakeLibraryPlaybackDriver::$calls);
        $this->assertSame('playLibraryTrack', FakeLibraryPlaybackDriver::$calls[0]['method']);
    }

    public function test_an_album_plays_in_track_order_not_in_the_order_it_was_scanned(): void
    {
        $artist = \App\Models\Media\Artist::create(['name' => 'A', 'source' => 'dlna']);
        $album = \App\Models\Media\Album::create(['name' => 'Al', 'artist_id' => $artist->id, 'source' => 'dlna']);
        $make = function (string $name, ?int $disc, ?int $number) use ($artist, $album) {
            $track = \App\Models\Media\Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => $name, 'external_id' => 'x:'.$name, 'source' => 'dlna']);
            foreach (['disc_number' => $disc, 'track_number' => $number] as $key => $value) {
                if ($value !== null) {
                    \App\Models\Media\Metadata::create(['metadatable_type' => \App\Models\Media\Track::class, 'metadatable_id' => $track->id, 'key' => $key, 'value' => (string) $value, 'type' => 'int', 'source' => 'local']);
                }
            }

            return $track;
        };

        // created in the order the files were scanned
        $make('five', null, 5);
        $make('unnumbered', null, null);
        $make('disc2-one', 2, 1);
        $make('one', null, 1);
        $make('three', 1, 3);

        $names = \App\Domain\Library\LibraryPlayback::albumTracks($album)->pluck('name')->all();

        $this->assertSame(['one', 'three', 'five', 'unnumbered', 'disc2-one'], $names);
    }

    public function test_album_play_can_be_limited_to_one_source(): void
    {
        $device = $this->makeDevice();
        $artist = Artist::create(['name' => 'Test Artist', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Test Album', 'source' => 'dlna']);
        $this->makeTrackWithDlnaUrl($album);
        $this->makeTrackWithoutDlnaUrl($album);

        $this->post(route('albums.play', [$album, $device, 'source' => 'dlna']))->assertSessionHas('success', fn ($m) => !str_contains($m, ' of '));
        $this->assertCount(1, FakeLibraryPlaybackDriver::$calls[0]['tracks']);

        // The device cannot play Spotify: limited to it, nothing plays and the flash says so.
        FakeLibraryPlaybackDriver::reset();
        $this->post(route('albums.play', [$album, $device, 'source' => 'spotify']))->assertSessionHas('error');
        $this->assertCount(0, FakeLibraryPlaybackDriver::$calls);

        $this->post(route('albums.play', [$album, $device, 'source' => 'tidal']))->assertSessionHasErrors('source');
    }
}
