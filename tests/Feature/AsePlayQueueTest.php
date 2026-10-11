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
