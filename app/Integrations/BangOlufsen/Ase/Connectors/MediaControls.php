<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

trait MediaControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function play(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Stream/Play', ['toBeReleased' => false]);
    }

    public function pause(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Stream/Pause', ['toBeReleased' => false]);
    }

    public function next(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Stream/Forward', ['toBeReleased' => false]);
    }

    public function previous(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Stream/Backward', ['toBeReleased' => false]);
    }

    public function stop(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Stream/Stop', ['toBeReleased' => false]);
    }

    public function wind(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Stream/Wind', ['toBeReleased' => false]);
    }

    public function rewind(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Stream/Rewind', ['toBeReleased' => false]);
    }

    public function shuffle(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/List/Shuffle', ['toBeReleased' => false]);
    }

    public function repeat(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/List/Repeat', ['toBeReleased' => false]);
    }
}
