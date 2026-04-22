<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\BangOlufsen\Ase\Connectors\ContentControls;
use App\Integrations\BangOlufsen\Ase\Connectors\DeviceControls;
use App\Integrations\BangOlufsen\Ase\Connectors\MediaControls;
use App\Integrations\BangOlufsen\Ase\Connectors\SourceControls;
use App\Integrations\BangOlufsen\Ase\Connectors\VolumeControls;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use App\Models\RadioStation;

class MusicPlayerDriver implements MediaControlsInterface, MusicPlayerDriverInterface, RadioControlInterface, SourceActivationInterface, SourcesInterface, VolumeControlInterface
{
    use ContentControls;
    use DeviceControls;
    use MediaControls;
    use SourceControls;
    use VolumeControls;

    public HttpConnector $deviceApi;

    public function __construct(public Device $device)
    {
        $this->deviceApi = new HttpConnector($device->ip_address.':8080');
    }

    public function deviceApiClient(): HttpConnector
    {
        return $this->deviceApi;
    }

    public function radioPlatform(): string
    {
        return 'beoradio';
    }

    public function canPlayRadioStation(RadioStation $station): bool
    {
        return $station->getMeta('beoradio') !== null;
    }

    public function playRadioStation(RadioStation $station): void
    {
        $this->playBeoRadioStation($station->getMeta('beoradio'));
    }

    public function getCurrentPlayingAttribute(): array
    {
        if (DeviceCache::getState($this->device->id) === State::Unreachable) {
            return [];
        }

        return DeviceCache::getNowPlaying($this->device->id)?->toArray() ?? [];
    }
}
