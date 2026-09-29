<?php

namespace App\Listeners\Device;

use App\Events\Device\VolumeUpdated;
use App\Services\MqttService;
use Illuminate\Support\Facades\Cache;

class PublishVolumeToMqtt
{
    public function __construct(
        protected MqttService $mqttService
    ) {}

    public function handle(VolumeUpdated $event): void
    {
        $deviceId = (int) $event->deviceId;
        if ($deviceId <= 0) {
            return;
        }

        // Listeners re-report the same level often; only publish real changes.
        $key = "mqtt_published_volume_{$deviceId}";
        if (Cache::get($key) === $event->volume) {
            return;
        }
        Cache::put($key, $event->volume, 3600);

        $this->mqttService->publish(
            "remoment/player/{$deviceId}/volume",
            json_encode(['volume' => $event->volume]),
            retain: true,
        );
    }
}
