<?php

namespace App\Listeners\Device;

use App\Events\Device\QueueUpdated;
use App\Services\MqttService;

/** A non-retained ping on remoment/player/{id}/queue: the queue changed, clients re-read it. */
class PublishQueueToMqtt
{
    public function __construct(
        protected MqttService $mqttService
    ) {}

    public function handle(QueueUpdated $event): void
    {
        if ($event->deviceId <= 0) {
            return;
        }

        $this->mqttService->publish("remoment/player/{$event->deviceId}/queue", '1');
    }
}
