<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Integrations\BangOlufsen\Ase\Connectors\DeviceControls;
use App\Integrations\BangOlufsen\Ase\Connectors\MediaControls;
use App\Integrations\BangOlufsen\Ase\Connectors\VolumeControls;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;

class VideoPlayerDriver implements MediaControlsInterface, MusicPlayerDriverInterface, VolumeControlInterface
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
        // Check if Listener is running
        $cacheKey = "listener_running_{$this->device->id}";

        if (!cache()->has($cacheKey)) {
            return [];
        }

        if (!cache()->has('device_data_'.$this->device->id.'_now_playing')) {
            return [];
        }

        $data = Cache::get('device_data_'.$this->device->id.'_now_playing');

        return $data['data'];
    }
}
