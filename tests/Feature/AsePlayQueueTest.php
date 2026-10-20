<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\BangOlufsen\Ase\MusicPlayerDriver;
use App\Integrations\BangOlufsen\Ase\Services\DeviceListener;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAseConnector;
use Tests\TestCase;

/** A DLNA track on an ASE player: the device only knows stream URLs, so queue and cover come from the library. */
class AsePlayQueueTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $name, string $url, ?string $cover = 'http://dlna.test/cover.jpg'): Track
    {
        $artist = Artist::firstOrCreate(['name' => 'Nirvana']);
        $album = Album::firstOrCreate(['name' => 'Unplugged', 'artist_id' => $artist->id], ['images' => $cover ? [$cover] : null]);
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'external_id' => uniqid('dlna:', true), 'name' => $name, 'source' => 'dlna', 'duration' => 200]);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'dlna_url', 'value' => $url, 'type' => 'url', 'source' => 'dlna:1']);

        return $track;
    }

    public function test_up_next_maps_queue_urls_to_library_tracks(): void
    {
        $device = Device::factory()->create(['ip_address' => '10.0.0.20', 'device_driver' => MusicPlayerDriver::class, 'device_driver_name' => 'ASE']);
        DeviceCache::updateState($device->id, State::Playing);
        $driver = $device->driver;
        $driver->deviceApi = $api = new FakeAseConnector;

        $this->track('Pennyroyal Tea', 'http://dlna.test/a/stream.mov?Tag=old');
        $api->seed('BeoZone/Zone/PlayQueue?offset=1&count=20', ['playQueue' => ['playQueueItem' => [
            ['id' => 'plid-2', 'track' => ['dlna' => ['url' => 'http://dlna.test/a/stream.mov?Tag=new']]],
            ['id' => 'plid-3', 'track' => ['dlna' => ['url' => 'http://dlna.test/unknown/stream.mov']]],
        ]]]);

        $items = $driver->getUpNext();

        $this->assertCount(1, $items);
        $this->assertSame('Pennyroyal Tea', $items[0]->name);
        $this->assertSame('Nirvana', $items[0]->artist);
        $this->assertSame('Unplugged', $items[0]->album);
        $this->assertSame('http://dlna.test/cover.jpg', $items[0]->image);
    }

    private function aseWithQueue(array $items): array
    {
        $device = Device::factory()->create(['ip_address' => '10.0.0.20', 'device_driver' => MusicPlayerDriver::class, 'device_driver_name' => 'ASE']);
        DeviceCache::updateState($device->id, State::Playing);
        $driver = $device->driver;
        $driver->deviceApi = $api = new FakeAseConnector;

        $listed = array_map(fn ($i) => ['id' => "plid-{$i[0]}", 'track' => ['dlna' => ['url' => $i[1]]]], $items);
        foreach ([1, 1000] as $count) {
            $api->seed("BeoZone/Zone/PlayQueue?offset=1&count={$count}", ['playQueue' => ['playQueueItem' => $listed]]);
        }

        return [$driver, $api];
    }

    public function test_queue_edits_address_items_by_the_positions_the_library_lists(): void
    {
        $this->track('One', 'http://dlna.test/1.flac');
        $this->track('Two', 'http://dlna.test/2.flac');
        // plid-11 is not in the library, so up-next position 1 is plid-12 and 2 is plid-13
        [$driver, $api] = $this->aseWithQueue([[11, 'http://dlna.test/unknown.flac'], [12, 'http://dlna.test/1.flac'], [13, 'http://dlna.test/2.flac']]);

        $driver->removeFromQueue(1, 'http://dlna.test/1.flac');
        $driver->moveInQueue(2, 1);
        $driver->clearQueue();

        $this->assertSame([
            'DELETE BeoZone/Zone/PlayQueue/plid-12',
            'PUT BeoZone/Zone/PlayQueue/plid-13?id=plid-12',
            'DELETE BeoZone/Zone/PlayQueue/plid-11',
            'DELETE BeoZone/Zone/PlayQueue/plid-12',
            'DELETE BeoZone/Zone/PlayQueue/plid-13',
        ], array_values(array_filter($api->requests, fn ($r) => !str_starts_with($r, 'GET'))));
    }

    public function test_a_stale_expectation_or_position_is_refused_before_anything_is_deleted(): void
    {
        $this->track('One', 'http://dlna.test/1.flac');
        [$driver, $api] = $this->aseWithQueue([[12, 'http://dlna.test/1.flac']]);

        foreach ([[1, 'http://dlna.test/other.flac'], [2, null]] as [$position, $expect]) {
            try {
                $driver->removeFromQueue($position, $expect);
                $this->fail('expected an InvalidArgumentException');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([], array_values(array_filter($api->requests, fn ($r) => !str_starts_with($r, 'GET'))));
    }

    public function test_play_next_inserts_before_the_item_that_follows_the_playing_one_and_add_appends(): void
    {
        $one = $this->track('One', 'http://dlna.test/1.flac');
        $two = $this->track('Two', 'http://dlna.test/2.flac');
        [$driver, $api] = $this->aseWithQueue([[12, 'http://dlna.test/1.flac']]);

        $driver->playNext(collect([$one, $two]));
        $driver->addToQueue(collect([$two]));

        $this->assertSame(['POST BeoZone/Zone/PlayQueue?id=plid-12', 'POST BeoZone/Zone/PlayQueue?id=plid-12', 'POST BeoZone/Zone/PlayQueue'], array_values(array_filter($api->requests, fn ($r) => !str_starts_with($r, 'GET'))));
    }

    public function test_stored_music_without_images_gets_the_library_cover(): void
    {
        $this->track('About A Girl', 'http://dlna.test/b/stream.mov');

        $nowPlaying = (new DeviceListener('http://example.test'))->parseStoredMusic([
            'artist' => 'Nirvana', 'album' => 'Unplugged', 'name' => 'About A Girl', 'duration' => 200,
            'albumImage' => [], 'trackImage' => [],
        ]);

        $this->assertSame(['http://dlna.test/cover.jpg'], $nowPlaying->album->images);
        $this->assertSame(['http://dlna.test/cover.jpg'], $nowPlaying->track->images);
    }

    public function test_stored_music_keeps_the_images_the_device_sent(): void
    {
        $nowPlaying = (new DeviceListener('http://example.test'))->parseStoredMusic([
            'artist' => 'A', 'album' => 'B', 'name' => 'C', 'duration' => 1,
            'albumImage' => ['http://x/a.jpg'], 'trackImage' => ['http://x/t.jpg'],
        ]);

        $this->assertSame(['http://x/a.jpg'], $nowPlaying->album->images);
    }
}
