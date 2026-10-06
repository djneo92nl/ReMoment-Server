<?php

namespace App\Listeners\Device;

use App\Events\Device\BatteryUpdated;
use App\Services\MqttService;
use Illuminate\Support\Facades\Cache;

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

        // Listeners re-report on a timer; only publish real changes.
        $key = "mqtt_published_battery_{$deviceId}";
        if (Cache::get($key) === $payload) {
            return;
        }
        Cache::put($key, $payload, 3600);

        $this->mqttService->publish("remoment/player/{$deviceId}/battery", $payload, retain: true);
    }
}
