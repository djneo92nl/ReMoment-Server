<?php

namespace Tests\Feature\Listeners\Device;

use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingUpdated;
use App\Listeners\Device\PublishNowPlayingToMqtt;
use App\Services\MqttService;
use Mockery\MockInterface;
use Tests\TestCase;

class PublishNowPlayingToMqttTest extends TestCase
{
    public function test_it_publishes_now_playing_data_to_mqtt()
    {
        $deviceId = "1";
        $trackName = "Bohemian Rhapsody";
        $artistName = "Queen";

        $track = new TrackData(name: $trackName, artist: new ArtistData(name: $artistName));
        $nowPlaying = new NowPlaying(track: $track);

        $expectedPayload = json_encode([
            'track' => $trackName,
            'artist' => $artistName,
        ]);

        $this->mock(MqttService::class, function (MockInterface $mock) use ($deviceId, $expectedPayload) {
            $mock->shouldReceive('publish')
                ->once()
                ->with("remoment/player/{$deviceId}/data", $expectedPayload);
        });

        $event = new NowPlayingUpdated($deviceId, $nowPlaying, 'media');
        $listener = app(PublishNowPlayingToMqtt::class);
        $listener->handle($event);
    }
}
