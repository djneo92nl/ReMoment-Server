<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\BangOlufsen\Ase\Connectors\BluetoothControls;
use App\Integrations\BangOlufsen\Ase\Connectors\ContentControls;
use App\Integrations\BangOlufsen\Ase\Connectors\DeviceControls;
use App\Integrations\BangOlufsen\Ase\Connectors\DeviceInfoControls;
use App\Integrations\BangOlufsen\Ase\Connectors\MediaControls;
use App\Integrations\BangOlufsen\Ase\Connectors\MultiRoomControls;
use App\Integrations\BangOlufsen\Ase\Connectors\NetworkControls;
use App\Integrations\BangOlufsen\Ase\Connectors\SoundAdjustmentControls;
use App\Integrations\BangOlufsen\Ase\Connectors\SourceControls;
use App\Integrations\BangOlufsen\Ase\Connectors\VolumeControls;
use App\Integrations\BangOlufsen\Ase\Connectors\WirelessSpeakerControls;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Contracts\BluetoothInterface;
use App\Integrations\Contracts\DeviceInfoInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\NetworkSettingsInterface;
use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\SoundAdjustmentInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Integrations\Contracts\WirelessSpeakersInterface;
use App\Models\Device;
use App\Models\RadioStation;

class MusicPlayerDriver implements BluetoothInterface, DeviceInfoInterface, MediaControlsInterface, MultiRoomInterface, MusicPlayerDriverInterface, NetworkSettingsInterface, PowerInterface, RadioControlInterface, SoundAdjustmentInterface, SourceActivationInterface, SourcesInterface, VolumeControlInterface, WirelessSpeakersInterface
{
    use BluetoothControls;
    use ContentControls;
    use DeviceControls;
    use DeviceInfoControls;
    use MediaControls;
    use MultiRoomControls;
    use NetworkControls;
    use SoundAdjustmentControls;
    use SourceControls;
    use VolumeControls;
    use WirelessSpeakerControls;

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
