<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Integrations\Common\UpnpMediaRendererDiscovery;

class AseDiscovery extends UpnpMediaRendererDiscovery
{
    protected function driverName(): string
    {
        return 'ASE';
    }
}
