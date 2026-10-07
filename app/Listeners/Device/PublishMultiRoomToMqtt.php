<?php

namespace App\Listeners\Device;

use App\Events\Device\MultiRoomUpdated;
use App\Models\Device;
use App\Services\MqttService;

class PublishMultiRoomToMqtt
{
    public function __construct(
        protected MqttService $mqttService
    ) {}

    public function handle(MultiRoomUpdated $event): void
    {
        $deviceId = (int) $event->deviceId;
        $device = $deviceId > 0 ? Device::find($deviceId) : null;
        if (!$device) {
            return;
        }

        $payload = json_encode($event->status->resolve($device));

        $this->mqttService->publishIfChanged("remoment/player/{$deviceId}/multiroom", $payload, "mqtt_published_multiroom_{$deviceId}");
    }
}
