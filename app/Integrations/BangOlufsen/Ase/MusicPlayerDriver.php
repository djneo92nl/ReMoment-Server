<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Integrations\BangOlufsen\Ase\Connectors\MediaControls;
use App\Integrations\BangOlufsen\Ase\Connectors\VolumeControls;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\VolumeControlInterface;

class MusicPlayerDriver implements MediaControlsInterface, VolumeControlInterface
{
    use MediaControls;
    use VolumeControls;

    public $deviceApi;

    public function __construct($ipAdress)
    {
        $this->deviceApi = new HttpConnector($ipAdress);
    }

    public function deviceApiClient(): HttpConnector
    {
        return $this->deviceApi;
    }
}
