<?php

namespace App\Listeners\Device;

use App\Events\Device\DeviceStateChanged;
use App\Services\MqttService;

class PublishStateToMqtt
{
    public function __construct(
        protected MqttService $mqttService
    ) {}

    public function handle(DeviceStateChanged $event): void
    {
        // Retained, so a subscriber that connects later immediately gets the current state.
        $this->mqttService->publish(
            "remoment/player/{$event->deviceId}/state",
            json_encode(['state' => $event->state->value]),
            retain: true,
        );
    }
}
