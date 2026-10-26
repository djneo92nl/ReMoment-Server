<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Integrations\BangOlufsen\Ase\Connectors\ContentControls;
use App\Integrations\BangOlufsen\Ase\Connectors\DeviceControls;
use App\Integrations\BangOlufsen\Ase\Connectors\DigitControls;
use App\Integrations\BangOlufsen\Ase\Connectors\LibraryPlayback;
use App\Integrations\BangOlufsen\Ase\Connectors\MediaControls;
use App\Integrations\BangOlufsen\Ase\Connectors\MultiRoomControls;
use App\Integrations\BangOlufsen\Ase\Connectors\SleepTimerControls;
use App\Integrations\BangOlufsen\Ase\Connectors\SourceControls;
use App\Integrations\BangOlufsen\Ase\Connectors\VolumeControls;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\DigitsInterface;
use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Integrations\Contracts\MultiRoomStatusInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\SessionHostInterface;
use App\Integrations\Contracts\SleepTimerInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VideoDeviceInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Listeners\Device\SwitchToDefaultSource;
use App\Models\Device;
use App\Models\DeviceMeta;

class VideoPlayerDriver implements DigitsInterface, LibraryPlaybackInterface, MediaControlsInterface, MultiRoomInterface, MultiRoomStatusInterface, MusicPlayerDriverInterface, PowerInterface, SessionHostInterface, SleepTimerInterface, SourceActivationInterface, SourcesInterface, VideoDeviceInterface, VolumeControlInterface
{
    use ContentControls;
    use DeviceControls;
    use DigitControls;
    use LibraryPlayback;
    use MediaControls;
    use MultiRoomControls;
    use SleepTimerControls;
    use SourceControls;
    use VolumeControls;

    public HttpConnector $deviceApi;

    public function __construct(public Device $device)
    {
        $this->deviceApi = new HttpConnector($device->ip_address.':8080');
    }

    /**
     * A TV takes no `on` in `powerManagement/standby` (it only lists `standby` and `allStandby`): sending it a
     * source is what wakes it (verified on the V1). The source is the device's default input
     * (`SwitchToDefaultSource::META_KEY`), else the first visible one of its own that is not a streaming service.
     */
    public function powerOn(): void
    {
        $sourceId = $this->wakeSourceId();

        if ($sourceId === null) {
            throw new UnsupportedOperationException('No source to switch on with: sync the sources first.');
        }

        $this->activateSource($sourceId);
    }

    private function wakeSourceId(): ?string
    {
        $default = DeviceMeta::where('device_id', $this->device->id)->where('key', SwitchToDefaultSource::META_KEY)->value('value');

        return $default ?: $this->device->deviceSources()
            ->where('borrowed', false)
            ->where('hidden', false)
            ->whereNotIn('source_type', ['SPOTIFY', 'DEEZER', 'TIDAL', 'QOBUZ'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('source_id');
    }

    public function deviceApiClient(): HttpConnector
    {
        return $this->deviceApi;
    }
}
