<?php

namespace App\Integrations\Sonos;

use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use duncan3dc\Sonos\Controller;
use duncan3dc\Sonos\Network;

class MusicPlayerDriver implements MediaControlsInterface, MusicPlayerDriverInterface, VolumeControlInterface
{
    public Controller $deviceApi;

    public function __construct(public Device $device)
    {
        $sonos = new Network;
        $controller = $sonos->getControllerByIp($device->ip_address);

    }

    public function deviceApiClient(): Controller
    {
        return $this->deviceApi;
    }

    public function getCurrentPlayingAttribute(): array {}

    public function play(): void
    {
        $this->deviceApi->pause();
    }

    public function pause(): void
    {
        $this->deviceApi->pause();
    }

    public function next(): void
    {
        $this->deviceApi->next();
    }

    public function previous(): void
    {
        $this->deviceApi->previous();
    }

    public function stop(): void
    {
        $this->deviceApi->pause();
    }

    public function setVolume(int $volume): int
    {
        $this->deviceApi->setVolume($volume);

        return $volume;
    }

    public function getVolume(): int
    {
        return $this->deviceApi->getVolume();
    }

    public function incrementVolume(): void
    {
        $this->deviceApi->adjustVolume(1);
    }

    public function decrementVolume(): void
    {
        $this->deviceApi->adjustVolume(-1);
    }

    public function mute(): void
    {
        $this->deviceApi->mute(true);
    }

    public function unmute(): void
    {
        $this->deviceApi->unmute(true);
    }
}
