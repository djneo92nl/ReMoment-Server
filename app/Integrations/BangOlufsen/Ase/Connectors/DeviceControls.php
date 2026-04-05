<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

trait DeviceControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function getPowerState(): string
    {
        $response = $this->deviceApiClient()->get('BeoDevice/powerManagement/standby');

        return $response['standby']['powerState'] ?? 'unknown';
    }

    public function standby(): void
    {
        $this->deviceApiClient()->put('BeoDevice/powerManagement/standby', [
            'standby' => ['powerState' => 'standby'],
        ]);
    }

    public function allStandby(): void
    {
        $this->deviceApiClient()->put('BeoDevice/powerManagement/standby', [
            'standby' => ['powerState' => 'allStandby'],
        ]);
    }

    public function reboot(): void
    {
        $this->deviceApiClient()->put('BeoDevice/powerManagement/standby', [
            'standby' => ['powerState' => 'reboot'],
        ]);
    }

    public function getStandPositions(): array
    {
        return $this->deviceApiClient()->get('BeoZone/Zone/Stand');
    }

    public function getActiveStandPosition(): int
    {
        $response = $this->deviceApiClient()->get('BeoZone/Zone/Stand/Active');

        return (int) ($response['active'] ?? 0);
    }

    public function setStandPosition(int $positionId): void
    {
        $this->deviceApiClient()->put('BeoZone/Zone/Stand/Active', ['active' => $positionId]);
    }

    public function setMotorizedSpeaker(string $preset): void
    {
        $this->deviceApiClient()->put('BeoDevice/motorizedSpeaker', [
            'motorizedSpeaker' => ['activePreset' => $preset],
        ]);
    }
}
