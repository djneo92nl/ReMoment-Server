<?php

namespace App\Listeners\Device;

use App\Events\Device\BatteryUpdated;
use App\Services\MqttService;

class PublishBatteryToMqtt
{
    public function __construct(
        protected MqttService $mqttService
    ) {}

    public function handle(BatteryUpdated $event): void
    {
        $deviceId = (int) $event->deviceId;
        if ($deviceId <= 0) {
            return;
        }

        $payload = json_encode($event->battery->toArray());

        $this->mqttService->publishIfChanged("remoment/player/{$deviceId}/battery", $payload, "mqtt_published_battery_{$deviceId}");
    }
}
