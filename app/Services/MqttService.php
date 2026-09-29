<?php

namespace App\Services;

use PhpMqtt\Client\MqttClient;

/**
 * Bound as a singleton: device listeners are long-running and publish
 * progress every second, so one connection is kept open and re-established
 * on failure instead of reconnecting per message.
 */
class MqttService
{
    protected ?MqttClient $client = null;

    public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
    {
        try {
            $this->client()->publish($topic, $message, $qualityOfService, $retain);
        } catch (\Exception) {
            // Stale connection (broker restart, idle timeout) — retry once on a fresh one.
            $this->disconnect();

            try {
                $this->client()->publish($topic, $message, $qualityOfService, $retain);
            } catch (\Exception $e) {
                $this->disconnect();
                report($e);
            }
        }
    }

    public function disconnect(): void
    {
        try {
            $this->client?->disconnect();
        } catch (\Exception) {
            // Already gone.
        }

        $this->client = null;
    }

    protected function client(): MqttClient
    {
        if ($this->client === null || !$this->client->isConnected()) {
            $client = new MqttClient(
                config('mqtt.host'),
                config('mqtt.port'),
                config('mqtt.client_id').'-'.getmypid(),
            );
            $client->connect();
            $this->client = $client;
        }

        return $this->client;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
