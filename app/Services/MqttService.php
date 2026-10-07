<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * Bound as a singleton: device listeners are long-running and publish
 * progress every second, so one connection is kept open and re-established
 * on failure instead of reconnecting per message.
 */
class MqttService
{
    /** Keep-alive we announce to the broker (it drops a silent client after 1.5x this). */
    private const KEEP_ALIVE_SECONDS = 60;

    /**
     * We only publish and never run the client loop, so no pings are sent. A connection that
     * stayed silent close to the keep-alive (e.g. a long pause) may already be closed by the
     * broker, and a QoS 0 publish into it fails without an error. Reconnect before that.
     */
    private const MAX_IDLE_SECONDS = 45;

    protected ?MqttClient $client = null;

    protected float $lastPublishAt = 0.0;

    public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
    {
        if ($this->client !== null && microtime(true) - $this->lastPublishAt > self::MAX_IDLE_SECONDS) {
            $this->disconnect();
        }
        $this->lastPublishAt = microtime(true);

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

    /**
     * Retained publish that skips a payload equal to the last one published under `$dedupeKey`:
     * device listeners re-report the same state on every poll, only real changes are worth a message.
     */
    public function publishIfChanged(string $topic, string $payload, string $dedupeKey): bool
    {
        if (Cache::get($dedupeKey) === $payload) {
            return false;
        }
        Cache::put($dedupeKey, $payload, 3600);

        $this->publish($topic, $payload, retain: true);

        return true;
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
            $client->connect((new ConnectionSettings)->setKeepAliveInterval(self::KEEP_ALIVE_SECONDS));
            $this->client = $client;
        }

        return $this->client;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
