<?php

namespace App\Services;

use PhpMqtt\Client\MqttClient;

class MqttService
{
    protected ?MqttClient $client = null;

    public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
    {
        $host = env('MQTT_HOST', 'localhost');
        $port = (int) env('MQTT_PORT', 1883);
        $clientId = env('MQTT_CLIENT_ID', 'remoment-server');

        try {
            $client = new MqttClient($host, $port, $clientId);
            $client->connect();
            $client->publish($topic, $message, $qualityOfService, $retain);
            $client->disconnect();
        } catch (\Exception $e) {
            // Log or handle the exception
            report($e);
        }
    }
}
