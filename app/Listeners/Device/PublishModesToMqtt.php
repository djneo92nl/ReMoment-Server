<?php

namespace App\Listeners\Device;

use App\Events\Device\PlaybackModesUpdated;
use App\Services\MqttService;
use Illuminate\Support\Facades\Cache;

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

        // Listeners re-report the same modes on every poll; only publish real changes.
        $payload = json_encode($event->modes->toArray());
        $key = "mqtt_published_modes_{$deviceId}";
        if (Cache::get($key) === $payload) {
            return;
        }
        Cache::put($key, $payload, 3600);

        $this->mqttService->publish("remoment/player/{$deviceId}/modes", $payload, retain: true);
    }
}
