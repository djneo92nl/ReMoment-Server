<?php

namespace App\Integrations\BangOlufsen\Ase\Services;

use GuzzleHttp\Client;

class DeviceListener
{
    protected string $url;

    protected Client $http;

    public function __construct(string $url)
    {
        $this->url = $url;
        $this->http = new Client([
            'timeout' => 0,        // allow hanging
            'read_timeout' => 0,   // allow streaming
            'stream' => true,
        ]);
    }

    public function listen(string $deviceId)
    {
        $cacheKey = "listener_running_{$deviceId}";
        cache()->put($cacheKey, true, now()->addMinutes(10));

        $response = $this->http->get($this->url, ['stream' => true]);
        $body = $response->getBody();

        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(1024);

            if ($chunk === '') {
                usleep(100000);

                continue;
            }

            $buffer .= $chunk;

            // Notifications are separated by \r\n\r\n (empty line)
            while (strpos($buffer, "\r\n\r\n") !== false) {
                [$json, $buffer] = explode("\r\n\r\n", $buffer, 2);

                $json = trim($json);

                if ($json === '') {
                    continue;
                }

                $decoded = json_decode($json, true);

                if ($decoded !== null) {
                    $parsed = $this->parseNotification($decoded);
                    cache()->put("device_data_{$deviceId}_{$parsed['type']}", $parsed);
                    dump("device_data_{$deviceId}_{$parsed['type']}");
                }
            }

            cache()->put($cacheKey, true, now()->addMinutes(10));
        }

        cache()->forget($cacheKey);
    }

    protected function parseNotification(array $payload): array
    {
        if (! isset($payload['notification'])) {
            return [];
        }

        $n = $payload['notification'];
        $data = $n['data'] ?? [];

        if ($n['type'] === 'NOW_PLAYING_NET_RADIO') {
            $data = $this->parseNetRadio($data);
            dump($data);
        }
        if ($n['type'] === 'NOW_PLAYING_STORED_MUSIC') {
            $data = $this->parseStoredMusic($data);
            dump($data);
        }

        return [
            'id' => $n['id'] ?? null,
            'timestamp' => $n['timestamp'] ?? null,
            'type' => $n['type'] ?? null,
            'kind' => $n['kind'] ?? null,
            'data' => $n['data'] ?? [],
        ];
    }

    public function parseNetRadio(array $payload): array
    {
        return [
            'track_name' => $payload['name'] ?? '',
            'artist_name' => $payload['liveDescription'] ?? '',
            'album_art' => $payload['image'][0]['url'] ?? '',
        ];
    }

    public function parseStoredMusic(array $payload): array
    {
        return [
            'track_name' => $payload['name'] ?? '',
            'track_duration' => $payload['duration'] ?? '',
            'track_id' => $payload['trackId'] ?? '',
            'artist_name' => $payload['artist'] ?? '',
            'album_name' => $payload['album'] ?? '',
            'album_art' => $payload['albumImage'][0]['url'] ?? '',
        ];
    }
}
