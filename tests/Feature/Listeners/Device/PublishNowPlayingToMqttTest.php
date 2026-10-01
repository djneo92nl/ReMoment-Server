<?php

namespace Tests\Feature\Listeners\Device;

use App\Domain\Artwork\SourceLogo;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingUpdated;
use App\Listeners\Device\PublishNowPlayingToMqtt;
use App\Services\MqttService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PublishNowPlayingToMqttTest extends TestCase
{
    public function test_it_publishes_now_playing_data_to_mqtt()
    {
        $deviceId = '1';
        $trackName = 'Bohemian Rhapsody';
        $artistName = 'Queen';

        $track = new TrackData(name: $trackName, artist: new ArtistData(name: $artistName));
        $nowPlaying = new NowPlaying(track: $track);

        // A track without an image gets the generated music-note logo.
        $this->mock(MqttService::class, function (MockInterface $mock) use ($deviceId, $trackName, $artistName) {
            $mock->shouldReceive('publish')
                ->once()
                ->with("remoment/player/{$deviceId}/data", Mockery::on(function (string $json) use ($trackName, $artistName) {
                    $payload = json_decode($json, true);

                    return $payload['track'] === $trackName
                        && $payload['artist'] === $artistName
                        && $payload['artwork']['kind'] === 'source'
                        && str_contains($payload['artwork']['proxy_320'], md5(SourceLogo::url('music')));
                }), 0, true);
        });

        $event = new NowPlayingUpdated($deviceId, $nowPlaying, 'media');
        $listener = app(PublishNowPlayingToMqtt::class);
        $listener->handle($event);
    }
}
