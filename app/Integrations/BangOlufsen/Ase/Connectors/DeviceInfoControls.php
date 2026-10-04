<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Device\Settings\DeviceInfo;

trait DeviceInfoControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function getDeviceInfo(): DeviceInfo
    {
        $device = $this->deviceApiClient()->getStrict('BeoDevice')['beoDevice'] ?? [];

        return new DeviceInfo(
            (string) ($device['productFriendlyName']['productFriendlyName'] ?? ''),
            $device['productId']['productType'] ?? null,
            $device['software']['version'] ?? null,
            $device['hardware']['mac'] ?? null,
        );
    }

    public function setDeviceName(string $name): void
    {
        $this->deviceApiClient()->putStrict('BeoDevice/productFriendlyName', [
            'productFriendlyName' => ['productFriendlyName' => $name],
        ]);

        if ($this->getDeviceInfo()->name !== $name) {
            throw new \RuntimeException('The device did not apply the new name.');
        }
    }
}
