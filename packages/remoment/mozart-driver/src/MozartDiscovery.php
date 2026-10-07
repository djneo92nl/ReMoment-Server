<?php

namespace Remoment\MozartDriver;

use App\Integrations\Common\UpnpMediaRendererDiscovery;

/**
 * Mozart devices are UPnP MediaRenderers in practice, so they are found like ASE ones.
 *
 * NOTE: this is unverified against real Mozart hardware; no discovery mechanism is
 * documented in the Mozart OpenAPI spec.
 */
class MozartDiscovery extends UpnpMediaRendererDiscovery
{
    protected function driverName(): string
    {
        return 'Mozart';
    }
}
