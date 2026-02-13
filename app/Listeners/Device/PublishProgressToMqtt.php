<?php

namespace App\Listeners\Device;

use App\Domain\Device\DeviceCache;
use App\Events\Device\ProgressUpdated;
use App\Services\MqttService;

class PublishProgressToMqtt
{
    /**
     * Create the event listener.
     */
    public function __construct(
        protected MqttService $mqttService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(ProgressUpdated $event): void
    {
        $deviceId = (int) $event->deviceId;
        if ($deviceId <= 0) {
            return;
        }

        $nowPlaying = DeviceCache::getNowPlaying($deviceId);

        if ($nowPlaying && $nowPlaying->track && $nowPlaying->track->duration) {
            $trackDuration = $nowPlaying->track->duration;

            // Math from issue description:
            // $nowPlaying->position = (int) (((int)$event->progress / (int)$trackDuration) * 100);
            $progressPercentage = (int) (((int)$event->progress / (int)$trackDuration) * 100);

            // Ensure it's between 0 and 100
            $progressPercentage = max(0, min(100, $progressPercentage));

            $this->mqttService->publish("remoment/player/{$deviceId}/progress", (string) $progressPercentage);
        }
    }
}
