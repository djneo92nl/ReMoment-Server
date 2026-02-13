<?php

namespace App\Listeners\Device;

use App\Events\Device\NowPlayingUpdated;
use App\Services\MqttService;

class PublishNowPlayingToMqtt
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
    public function handle(NowPlayingUpdated $event): void
    {
        $deviceId = $event->deviceId;
        $nowPlaying = $event->nowPlaying;

        $data = [
            'track' => $nowPlaying->track?->name,
            'artist' => $nowPlaying->artist?->name,
        ];

        $this->mqttService->publish("remoment/player/{$deviceId}/data", json_encode($data));
    }
}
