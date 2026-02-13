<?php

namespace Tests\Feature\Listeners\Device;

use App\Domain\Device\DeviceCache;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Track;
use App\Events\Device\ProgressUpdated;
use App\Listeners\Device\PublishProgressToMqtt;
use App\Services\MqttService;
use Mockery\MockInterface;
use Tests\TestCase;

class PublishProgressToMqttTest extends TestCase
{
    public function test_it_publishes_progress_to_mqtt()
    {
        $deviceId = 1;
        $progress = 150; // 150 seconds
        $duration = 300; // 300 seconds
        // Math: (150 / 300) * 100 = 50

        $track = new Track(duration: $duration);
        $nowPlaying = new NowPlaying(track: $track);

        (new DeviceCache())->updateNowPlaying($deviceId, $nowPlaying);

        $this->mock(MqttService::class, function (MockInterface $mock) use ($deviceId) {
            $mock->shouldReceive('publish')
                ->once()
                ->with("remoment/player/{$deviceId}/progress", "50");
        });

        $event = new ProgressUpdated((string)$deviceId, $progress);
        $listener = app(PublishProgressToMqtt::class);
        $listener->handle($event);
    }

    public function test_it_handles_missing_now_playing()
    {
        $deviceId = 2;
        $progress = 100;

        DeviceCache::forget($deviceId);
        DeviceCache::forgetNowPlaying($deviceId);

        $this->mock(MqttService::class, function (MockInterface $mock) {
            $mock->shouldReceive('publish')->never();
        });

        $event = new ProgressUpdated((string)$deviceId, $progress);
        $listener = app(PublishProgressToMqtt::class);
        $listener->handle($event);
    }
}
