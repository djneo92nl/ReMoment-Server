<?php

namespace Tests\Feature;

use App\Integrations\Contracts\QueueAddInterface;
use App\Integrations\Contracts\QueueRemoveInterface;
use App\Integrations\Spotify\MusicPlayerDriver;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesSpotify;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

class SpotifyQueueAddTest extends TestCase
{
    use FakesSpotify;
    use MakesLibraryTracks;
    use RefreshDatabase;

    private function album(): Album
    {
        return Album::create(['name' => 'Album', 'artist_id' => Artist::create(['name' => 'Artist'])->id]);
    }

    public function test_spotify_appends_the_tracks_it_has_and_can_do_nothing_else(): void
    {
        $album = $this->album();
        $one = $this->makeLibraryTrack($album, 'One', dlna: false, spotifyId: 'aaa');
        $local = $this->makeLibraryTrack($album, 'Local');
        $two = $this->makeLibraryTrack($album, 'Two', dlna: false, spotifyId: 'bbb');

        $api = $this->connectSpotify();
        $api->shouldReceive('queue')->once()->with('spotify:track:aaa')->andReturnTrue();
        $api->shouldReceive('queue')->once()->with('spotify:track:bbb')->andReturnTrue();

        $driver = new MusicPlayerDriver(Device::factory()->create());
        $driver->addToQueue(collect([$one, $local, $two]));

        $this->assertNotInstanceOf(QueueRemoveInterface::class, $driver);
        $this->assertInstanceOf(QueueAddInterface::class, $driver);
    }

    public function test_none_on_spotify_is_an_invalid_request(): void
    {
        $track = $this->makeLibraryTrack($this->album(), 'Local');
        $this->connectSpotify();

        $this->expectException(\InvalidArgumentException::class);
        (new MusicPlayerDriver(Device::factory()->create()))->addToQueue(collect([$track]));
    }
}
