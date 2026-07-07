<?php

namespace Djneo92nl\BeoMozart\Api;

use Djneo92nl\BeoMozart\Http\HttpTransportInterface;

class PowerApi
{
    public function __construct(protected HttpTransportInterface $transport) {}

    public function getProductState(): ?array
    {
        return $this->transport->request('GET', '/api/v1/state');
    }

    public function getPowerState(): ?string
    {
        $response = $this->transport->request('GET', '/api/v1/state/power');

        return $response['value'] ?? null;
    }

    /**
     * NOTE: the spec has no "power on" endpoint — only standby and reboot.
     */
    public function standby(): void
    {
        $this->transport->request('PUT', '/api/v1/state/standby');
    }

    public function reboot(): void
    {
        $this->transport->request('PUT', '/api/v1/state/reboot');
    }
}
