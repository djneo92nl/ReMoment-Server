<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

trait MediaControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function play(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Stream/Play', []);
    }

    public function pause(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Stream/Pause', []);
    }

    public function next(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Stream/Forward', []);
    }

    public function previous(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Stream/Rewind', []);
    }

    public function stop(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Stream/Stop', []);

    }
}
