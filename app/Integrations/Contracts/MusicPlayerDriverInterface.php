<?php

namespace App\Integrations\Contracts;

use App\Models\Device;

interface MusicPlayerDriverInterface
{
    public function __construct(Device $device);

    public function getIsNowPlayingAttribute();
}
