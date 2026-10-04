<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Device\Settings\BluetoothDevice;
use App\Domain\Device\Settings\BluetoothState;
use App\Integrations\Common\UnsupportedOperationException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

trait BluetoothControls
{
    private const BLUETOOTH = 'BeoDevice/bluetoothSettings/';

    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function getBluetooth(): BluetoothState
    {
        $bluetooth = $this->bluetoothResource();
        $settings = $bluetooth['deviceSettings'] ?? [];

        return new BluetoothState(
            (bool) ($settings['bluetoothOn'] ?? false),
            ($settings['discoverabilityMode'] ?? 'notDiscoverable') === 'discoverable',
            $settings['reconnectMode'] ?? null,
            in_array('reconnectMode', $settings['_capabilities']['editable'] ?? [], true)
                ? array_values($settings['_capabilities']['value']['reconnectMode'] ?? [])
                : [],
            in_array('discoverabilityMode', $settings['_capabilities']['editable'] ?? [], true),
            // `paired: false` entries are devices the speaker has seen, not paired ones.
            array_map(
                fn (array $device) => $this->bluetoothDevice($device),
                array_values(array_filter($bluetooth['devices']['device'] ?? [], fn (array $d) => ($d['paired'] ?? true) !== false))
            ),
        );
    }

    public function setBluetooth(?bool $discoverable = null, ?string $reconnectMode = null): void
    {
        $settings = $this->bluetoothResource()['deviceSettings'] ?? [];

        if ($reconnectMode !== null && !in_array($reconnectMode, $settings['_capabilities']['value']['reconnectMode'] ?? [], true)) {
            throw new \InvalidArgumentException("Unknown reconnect mode '{$reconnectMode}'.");
        }

        // This resource rejects a partial body ("A string was expected. Key: deviceName"),
        // so every field goes back, changed or not: the editable ones, and
        // `bluetoothOn` too, which is read-only but still required ("A boolean was expected").
        $body = [
            'deviceName' => $settings['deviceName'] ?? '',
            'bluetoothOn' => (bool) ($settings['bluetoothOn'] ?? false),
            'discoverabilityMode' => $discoverable === null
                ? ($settings['discoverabilityMode'] ?? 'notDiscoverable')
                : ($discoverable ? 'discoverable' : 'notDiscoverable'),
            'alwaysOpen' => (bool) ($settings['alwaysOpen'] ?? false),
            'reconnectMode' => $reconnectMode ?? $settings['reconnectMode'] ?? 'manual',
        ];

        $this->deviceApiClient()->putStrict(self::BLUETOOTH.'deviceSettings', ['deviceSettings' => $body]);

        $applied = $this->bluetoothResource()['deviceSettings'] ?? [];
        if (($applied['discoverabilityMode'] ?? null) !== $body['discoverabilityMode'] || ($applied['reconnectMode'] ?? null) !== $body['reconnectMode']) {
            throw new \RuntimeException('The device did not apply the Bluetooth change.');
        }
    }

    public function removeBluetoothDevice(string $id): void
    {
        // The device's ids are URL-encoded ("FC%3aB2%3a…"); a REST client's path segment arrives decoded.
        foreach ($this->bluetoothResource()['devices']['device'] ?? [] as $device) {
            if (rawurldecode($this->bluetoothDevice($device)->id) !== rawurldecode($id)) {
                continue;
            }

            $href = $device['_links']['/relation/delete']['href'] ?? null;
            if ($href === null) {
                throw new UnsupportedOperationException('This device does not allow removing that Bluetooth device.');
            }

            // Links are relative to the resource they were read from.
            $base = new Uri('http://device/'.self::BLUETOOTH);
            $this->deviceApiClient()->deleteStrict(ltrim(UriResolver::resolve($base, new Uri($href))->getPath(), '/'));

            // The device answers before it has applied the removal; a read straight after still lists the device.
            for ($try = 0; $try < 8; $try++) {
                $stillPaired = array_filter(
                    $this->bluetoothResource()['devices']['device'] ?? [],
                    fn (array $d) => rawurldecode($this->bluetoothDevice($d)->id) === rawurldecode($id)
                );
                if (!$stillPaired) {
                    return;
                }
                $this->settleBluetooth();
            }

            throw new \RuntimeException('The device did not remove the Bluetooth device.');
        }

        throw new \InvalidArgumentException('That Bluetooth device is not paired.');
    }

    protected function settleBluetooth(): void
    {
        usleep(500_000);
    }

    private function bluetoothResource(): array
    {
        return $this->deviceApiClient()->getStrict(self::BLUETOOTH)['profile']['bluetooth'] ?? [];
    }

    private function bluetoothDevice(array $device): BluetoothDevice
    {
        $address = $device['deviceAddress'] ?? $device['address'] ?? null;

        return new BluetoothDevice(
            (string) ($device['id'] ?? $address ?? ''),
            (string) ($device['deviceName'] ?? $device['name'] ?? $address ?? ''),
            $address,
            (bool) ($device['connected'] ?? false),
        );
    }
}
