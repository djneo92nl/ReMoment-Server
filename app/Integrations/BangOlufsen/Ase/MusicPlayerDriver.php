<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\BangOlufsen\Ase\Connectors\DeviceControls;
use App\Integrations\BangOlufsen\Ase\Connectors\MediaControls;
use App\Integrations\BangOlufsen\Ase\Connectors\VolumeControls;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;

class MusicPlayerDriver implements MediaControlsInterface, MusicPlayerDriverInterface, VolumeControlInterface
{
    use DeviceControls;
    use MediaControls;
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

    public function getCurrentPlayingAttribute(): array
    {
        if (DeviceCache::getState($this->device->id) === State::Unreachable) {
            return [];
        }

        return DeviceCache::getNowPlaying($this->device->id)->toArray() ?? [];
    }
}
