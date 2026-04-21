<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\DiscoveredDevice;

interface DiscoveryInterface
{
    /**
     * Scan the network and return discovered devices without persisting them.
     *
     * @return DiscoveredDevice[]
     */
    public function discover(): array;
}
