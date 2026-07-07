<?php

namespace Djneo92nl\BeoMozart\Api;

use Djneo92nl\BeoMozart\Enums\PlaybackCommand;
use Djneo92nl\BeoMozart\Http\HttpTransportInterface;

class PlaybackApi
{
    public function __construct(protected HttpTransportInterface $transport) {}

    public function getState(): ?array
    {
        return $this->transport->request('GET', '/api/v1/playback/state');
    }

    public function getQueueSettings(): ?array
    {
        return $this->transport->request('GET', '/api/v1/playback/queue/settings');
    }

    public function setQueueSettings(array $settings): ?array
    {
        return $this->transport->request('PUT', '/api/v1/playback/queue/settings', $settings);
    }

    public function sendCommand(PlaybackCommand $command): void
    {
        $this->transport->request('POST', "/api/v1/playback/command/{$command->value}");
    }

    public function seek(int $positionMs): void
    {
        $this->transport->request('PUT', '/api/v1/playback/seek', [], ['position_ms' => $positionMs]);
    }

    public function playUri(string $location): void
    {
        $this->transport->request('POST', '/api/v1/playback/uri', ['location' => $location]);
    }

    /**
     * Append (or start) a queue item. $providerValue is one of the schema-backed
     * PlayQueueItemType values: uri, dlna, radio, deezer, beoCloud, tidal.
     */
    public function enqueue(string $type, string $providerValue, string $uri, ?int $startNowFromPosition = null): void
    {
        $body = [
            'type' => $type,
            'provider' => ['value' => $providerValue],
            'uri' => $uri,
        ];

        if ($startNowFromPosition !== null) {
            $body['startNowFromPosition'] = $startNowFromPosition;
        }

        $this->transport->request('POST', '/api/v1/playback/queue', $body);
    }

    public function clearQueue(): void
    {
        $this->transport->request('POST', '/api/v1/playback/queue/clear');
    }
}
