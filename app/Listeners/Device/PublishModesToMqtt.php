<?php

namespace App\Listeners\Device;

use App\Events\Device\PlaybackModesUpdated;
use App\Services\MqttService;

class PublishModesToMqtt
{
    public function __construct(
        protected MqttService $mqttService
    ) {}

    public function handle(PlaybackModesUpdated $event): void
    {
        $deviceId = (int) $event->deviceId;
        if ($deviceId <= 0) {
            return;
        }

        $this->mqttService->publishIfChanged("remoment/player/{$deviceId}/modes", json_encode($event->modes->toArray()), "mqtt_published_modes_{$deviceId}");
    }
}
