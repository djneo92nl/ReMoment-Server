<?php

namespace App\Integrations\Sonos;

use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use App\Models\RadioStation;
use duncan3dc\Sonos\Controller;
use duncan3dc\Sonos\Devices\Collection;
use duncan3dc\Sonos\Network;
use duncan3dc\Sonos\Tracks\Stream;

class MusicPlayerDriver implements MediaControlsInterface, MusicPlayerDriverInterface, RadioControlInterface, VolumeControlInterface
{
    public Controller $deviceApi;

    public function __construct(public Device $device)
    {
        $collection = (new Collection)->addIp($device->ip_address);
        $sonos = new Network($collection);
        $this->deviceApi = $sonos->getControllerByIp($device->ip_address);
    }

    public function deviceApiClient(): Controller
    {
        return $this->deviceApi;
    }

    public function getCurrentPlayingAttribute(): array {}

    public function radioPlatform(): string
    {
        return 'tunein';
    }

    public function canPlayRadioStation(RadioStation $station): bool
    {
        return $station->getMeta('tunein') !== null;
    }

    public function playRadioStation(RadioStation $station): void
    {
        $id = $station->getMeta('tunein');
        $uri = "x-sonosapi-stream:{$id}?sid=254&flags=8224&sn=0";
        $this->deviceApi->useStream(new Stream($uri, $station->name));
    }

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
