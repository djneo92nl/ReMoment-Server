<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Integrations\BangOlufsen\Ase\Connectors\MediaControls;
use App\Integrations\BangOlufsen\Ase\Connectors\VolumeControls;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;

class MusicPlayerDriver implements MediaControlsInterface, VolumeControlInterface
{
    use MediaControls;
    use VolumeControls;

    public $deviceApi;

    public function __construct(Device $device)
    {
        $this->deviceApi = new HttpConnector($device->ip_address);
    }

    public function deviceApiClient(): HttpConnector
    {
        return $this->deviceApi;
    }

    public function getIsNowPlayingAttribute(): bool
    {
        // Check if Listener is running
        $cacheKey = "listener_running_{$id}";

        return false;
    }
}
