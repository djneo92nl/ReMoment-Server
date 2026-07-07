<?php

namespace Djneo92nl\BeoMozart\Api;

use Djneo92nl\BeoMozart\Http\HttpTransportInterface;

class VolumeApi
{
    public function __construct(protected HttpTransportInterface $transport) {}

    public function getState(): ?array
    {
        return $this->transport->request('GET', '/api/v1/sound/volume');
    }

    public function setLevel(int $level): void
    {
        $this->transport->request('PUT', '/api/v1/sound/volume/level', ['level' => $level]);
    }

    public function setMuted(bool $muted): void
    {
        $this->transport->request('PUT', '/api/v1/sound/volume/mute', ['muted' => $muted]);
    }

    public function getSettings(): ?array
    {
        return $this->transport->request('GET', '/api/v1/sound/volume/settings');
    }

    public function setSettings(array $settings): ?array
    {
        return $this->transport->request('PUT', '/api/v1/sound/volume/settings', $settings);
    }
}
