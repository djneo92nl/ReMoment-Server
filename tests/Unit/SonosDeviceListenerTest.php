<?php

namespace Tests\Unit;

use App\Domain\Media\NowPlaying;
use App\Integrations\Sonos\Services\DeviceListener;
use duncan3dc\Sonos\Device;
use duncan3dc\Sonos\Network;
use duncan3dc\Sonos\State as SonosState;
use ReflectionClass;
use Tests\TestCase;

class SonosDeviceListenerTest extends TestCase
{
    public function test_to_seconds_parses_time_strings(): void
    {
        $listener = $this->makeListener();

        $this->assertSame(3723, $this->callProtected($listener, 'toSeconds', ['01:02:03']));
        $this->assertSame(330, $this->callProtected($listener, 'toSeconds', ['05:30']));
        $this->assertSame(7, $this->callProtected($listener, 'toSeconds', ['7']));
        $this->assertNull($this->callProtected($listener, 'toSeconds', ['']));
    }

    public function test_build_now_playing_from_stream(): void
    {
        $listener = $this->makeListener();

        $state = new SonosState('x-rincon-mp3radio://example');
        $state->stream = 'Station Name';
        $state->title = 'Track Name';
        $state->artist = 'Artist Name';
        $state->albumArt = 'http://sonos.example/art.jpg';
        $state->duration = '00:04:20';
        $state->position = '00:01:10';

        $nowPlaying = $this->callProtected($listener, 'buildNowPlaying', [$state]);

        $this->assertInstanceOf(NowPlaying::class, $nowPlaying);
        $this->assertSame('music', $nowPlaying->type);
        $this->assertSame('radio', $nowPlaying->platform);
        $this->assertSame('Station Name', $nowPlaying->radio?->name);
        $this->assertSame('Track Name', $nowPlaying->track?->name);
        $this->assertSame('Artist Name', $nowPlaying->artist?->name);
        $this->assertSame(['http://sonos.example/art.jpg'], $nowPlaying->track?->images);
        $this->assertSame(260, $nowPlaying->track?->duration);
        $this->assertSame(70, $nowPlaying->position);
    }

    public function test_build_now_playing_from_track_details(): void
    {
        $listener = $this->makeListener();

        $state = new SonosState('x-file-cifs://example');
        $state->stream = '';
        $state->title = 'Track Name';
        $state->artist = 'Artist Name';
        $state->album = 'Album Name';
        $state->albumArt = 'http://sonos.example/art.jpg';
        $state->duration = '00:03:30';
        $state->position = '00:00:15';

        $nowPlaying = $this->callProtected($listener, 'buildNowPlaying', [$state]);

        $this->assertInstanceOf(NowPlaying::class, $nowPlaying);
        $this->assertSame('music', $nowPlaying->type);
        $this->assertSame('media', $nowPlaying->platform);
        $this->assertSame('Track Name', $nowPlaying->track?->name);
        $this->assertSame('Artist Name', $nowPlaying->artist?->name);
        $this->assertSame('Album Name', $nowPlaying->album?->name);
        $this->assertSame(['http://sonos.example/art.jpg'], $nowPlaying->track?->images);
        $this->assertSame(210, $nowPlaying->track?->duration);
        $this->assertSame(15, $nowPlaying->position);
    }

    public function test_build_now_playing_returns_null_when_empty(): void
    {
        $listener = $this->makeListener();

        $state = new SonosState('x-file-cifs://example');
        $state->stream = '';
        $state->title = '';

        $nowPlaying = $this->callProtected($listener, 'buildNowPlaying', [$state]);

        $this->assertNull($nowPlaying);
    }

    private function makeListener(): DeviceListener
    {
        $device = $this->createMock(Device::class);
        $network = $this->createMock(Network::class);

        return new DeviceListener($device, $network);
    }

    private function callProtected(object $object, string $method, array $args = [])
    {
        $reflection = new ReflectionClass($object);
        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $args);
    }
}
