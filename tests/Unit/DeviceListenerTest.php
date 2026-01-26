<?php

namespace Tests\Unit;

use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;
use App\Integrations\BangOlufsen\Ase\Services\DeviceListener;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use Tests\TestCase;

class DeviceListenerTest extends TestCase
{
    public function test_parse_net_radio_with_artist_and_track(): void
    {
        $listener = new DeviceListener('http://example.test');

        $payload = [
            'name' => 'Radio One',
            'image' => ['https://example.test/radio.png'],
            'liveDescription' => 'Artist Name - Track Name',
        ];

        $nowPlaying = $listener->parseNetRadio($payload);

        $this->assertSame('music', $nowPlaying->type);
        $this->assertSame('radio', $nowPlaying->platform);
        $this->assertSame('Radio One', $nowPlaying->radio?->name);
        $this->assertSame('Artist Name', $nowPlaying->artist?->name);
        $this->assertSame('Track Name', $nowPlaying->track?->name);
        $this->assertSame($payload['image'], $nowPlaying->track?->images);
    }

    public function test_parse_net_radio_without_artist_separator(): void
    {
        $listener = new DeviceListener('http://example.test');

        $payload = [
            'name' => 'Radio Two',
            'image' => ['https://example.test/radio2.png'],
            'liveDescription' => 'Just A Station Name',
        ];

        $nowPlaying = $listener->parseNetRadio($payload);

        $this->assertSame('Radio Two', $nowPlaying->radio?->name);
        $this->assertSame('Just A Station Name', $nowPlaying->track?->name);
        $this->assertNull($nowPlaying->artist);
        $this->assertNull($nowPlaying->type);
        $this->assertNull($nowPlaying->platform);
    }

    public function test_parse_stored_music_populates_track_album_and_artist(): void
    {
        $listener = new DeviceListener('http://example.test');

        $payload = [
            'artist' => 'Artist Name',
            'album' => 'Album Name',
            'albumImage' => ['https://example.test/album.png'],
            'name' => 'Track Name',
            'duration' => 210,
            'trackImage' => ['https://example.test/track.png'],
            'trackId' => rawurlencode('spotify:track:123'),
        ];

        $nowPlaying = $listener->parseStoredMusic($payload);

        $this->assertSame('Artist Name', $nowPlaying->artist?->name);
        $this->assertSame('Album Name', $nowPlaying->album?->name);
        $this->assertSame('Track Name', $nowPlaying->track?->name);
        $this->assertSame(210, $nowPlaying->track?->duration);
        $this->assertSame('spotify:track:123', $nowPlaying->track?->meta['spotifyId']);
        $this->assertSame('music', $nowPlaying->type);
        $this->assertSame('media', $nowPlaying->platform);
    }

    public function test_parse_source_builds_video_now_playing(): void
    {
        $listener = new DeviceListener('http://example.test');

        $payload = [
            'primaryExperience' => [
                'source' => [
                    'friendlyName' => 'HDMI 1',
                    'category' => 'video',
                    'id' => 'source-1',
                    'sourceType' => [
                        'type' => 'HDMI',
                        'connector' => 'HDMI_A',
                    ],
                ],
            ],
        ];

        $method = (new ReflectionClass(DeviceListener::class))->getMethod('parseSource');
        $method->setAccessible(true);

        $nowPlaying = $method->invoke($listener, $payload);

        $this->assertSame('video', $nowPlaying->type);
        $this->assertSame('media', $nowPlaying->platform);
        $this->assertSame('HDMI 1', $nowPlaying->source?->name);
        $this->assertSame('video', $nowPlaying->source?->category);
        $this->assertSame('source-1', $nowPlaying->source?->jid);
        $this->assertSame('HDMI', $nowPlaying->source?->sourceType);
        $this->assertSame('HDMI_A', $nowPlaying->source?->connector);
    }

    public function test_apply_notification_dispatches_events(): void
    {
        Event::fake();

        $listener = new DeviceListener('http://example.test');
        $method = (new ReflectionClass(DeviceListener::class))->getMethod('applyNotification');
        $method->setAccessible(true);

        $method->invoke($listener, [
            'notification' => [
                'type' => 'NOW_PLAYING_NET_RADIO',
                'timestamp' => 123,
                'data' => [
                    'name' => 'Radio One',
                    'image' => ['https://example.test/radio.png'],
                    'liveDescription' => 'Artist Name - Track Name',
                ],
            ],
        ], 'device-1');

        $method->invoke($listener, [
            'notification' => [
                'type' => 'VOLUME',
                'data' => [
                    'speaker' => [
                        'level' => 42,
                    ],
                ],
            ],
        ], 'device-1');

        $method->invoke($listener, [
            'notification' => [
                'type' => 'SOURCE',
                'data' => [],
            ],
        ], 'device-1');

        $method->invoke($listener, [
            'notification' => [
                'type' => 'PROGRESS_INFORMATION',
                'data' => [
                    'position' => 99,
                ],
            ],
        ], 'device-1');

        Event::assertDispatched(NowPlayingUpdated::class, function (NowPlayingUpdated $event) {
            return $event->deviceId === 'device-1'
                && $event->sourceType === 'radio'
                && $event->timestamp === 123
                && $event->nowPlaying->radio?->name === 'Radio One';
        });

        Event::assertDispatched(VolumeUpdated::class, function (VolumeUpdated $event) {
            return $event->deviceId === 'device-1'
                && $event->volume === 42;
        });

        Event::assertDispatched(NowPlayingEnded::class, function (NowPlayingEnded $event) {
            return $event->deviceId === 'device-1';
        });

        Event::assertDispatched(ProgressUpdated::class, function (ProgressUpdated $event) {
            return $event->deviceId === 'device-1'
                && $event->progress === 99;
        });
    }
}
