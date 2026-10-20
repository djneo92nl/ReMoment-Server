<?php

namespace Tests\Unit\Mozart;

use App\Integrations\Contracts\QueueInterface;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use Djneo92nl\BeoMozart\MozartClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Remoment\MozartDriver\MusicPlayerDriver;
use Tests\Support\FakeMozartTransport;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

class MozartQueueTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private function driver(FakeMozartTransport $transport): MusicPlayerDriver
    {
        $driver = new MusicPlayerDriver(new Device(['device_name' => 'Beosound Balance', 'ip_address' => '10.0.0.5']));
        $driver->client = new MozartClient('mozart.test', transport: $transport);

        return $driver;
    }

    private function tracks(int $count): \Illuminate\Support\Collection
    {
        $album = Album::create(['name' => 'A', 'artist_id' => Artist::create(['name' => 'B'])->id]);

        return collect(range(1, $count))->map(fn ($i) => $this->makeLibraryTrack($album, "Track {$i}"));
    }

    public function test_tracks_are_appended_and_played_next_by_queue_index_and_the_queue_cleared(): void
    {
        $transport = new FakeMozartTransport;
        $driver = $this->driver($transport);
        $tracks = $this->tracks(2);

        $driver->addToQueue($tracks);
        $driver->playNext($tracks);
        $driver->clearQueue();

        $this->assertSame(['POST /api/v1/playback/queue', 'POST /api/v1/playback/queue', 'POST /api/v1/playback/queue', 'POST /api/v1/playback/queue', 'POST /api/v1/playback/queue/clear'], $transport->requests);
        $this->assertArrayNotHasKey('index', $transport->bodies[0]);
        $this->assertSame([1, 2], [$transport->bodies[2]['index'], $transport->bodies[3]['index']]);
        $this->assertSame($tracks[0]->getDlnaUrl(), $transport->bodies[2]['uri']);
    }

    public function test_the_spec_has_no_queue_read_so_it_is_not_claimed(): void
    {
        $this->assertNotInstanceOf(QueueInterface::class, $this->driver(new FakeMozartTransport));
    }

    public function test_tracks_without_a_stream_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->driver(new FakeMozartTransport)->addToQueue(collect([new Track(['name' => 'x'])]));
    }
}
