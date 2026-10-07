<?php

namespace App\Listeners\Device;

use App\Events\Device\MultiRoomUpdated;
use App\Models\Device;
use App\Services\MqttService;
use Illuminate\Support\Facades\Cache;

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

        // Only publish real changes.
        $key = "mqtt_published_multiroom_{$deviceId}";
        if (Cache::get($key) === $payload) {
            return;
        }
        Cache::put($key, $payload, 3600);

        $this->mqttService->publish("remoment/player/{$deviceId}/multiroom", $payload, retain: true);
    }
}
