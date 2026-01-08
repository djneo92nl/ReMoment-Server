<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

trait DeviceControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function standby(): void
    {
        $this->deviceApiClient()->put('BeoDevice/powerManagement/standby', [
            'standby' => [
                'powerState' => 'standby',
            ],
        ]);
    }
}
