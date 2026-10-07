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
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;

class VideoPlayerDriver implements DigitsInterface, LibraryPlaybackInterface, MediaControlsInterface, MultiRoomInterface, MultiRoomStatusInterface, MusicPlayerDriverInterface, PowerInterface, SessionHostInterface, SleepTimerInterface, SourceActivationInterface, SourcesInterface, VolumeControlInterface
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

    public function deviceApiClient(): HttpConnector
    {
        return $this->deviceApi;
    }
}
