<?php

namespace App\Listeners\Device;

use App\Domain\Device\Cache\Volume;
use App\Events\Device\VolumeUpdated;
use App\Services\MqttService;

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

        $payload = json_encode([
            'volume' => $event->volume,
            // A listener that doesn't report mute keeps the last known value.
            'muted' => $event->muted ?? Volume::getMuted($deviceId) ?? false,
        ]);

        $this->mqttService->publishIfChanged("remoment/player/{$deviceId}/volume", $payload, "mqtt_published_volume_{$deviceId}");
    }
}
