<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Device\Settings\NetworkConnection;
use App\Domain\Device\Settings\NetworkState;
use App\Domain\Device\Settings\WifiNetwork;

/**
 * Network settings of an ASE speaker. Bodies follow what the device itself
 * accepted when probed with invalid values: `profile.networkSettings` for the
 * active interface, `wired` and `wireless.activeNetwork` for the rest.
 *
 * The writes can change the address the device answers on (new interface, new
 * static address, another Wi-Fi network), so none of them reads the result
 * back: the caller re-reads once the device is back.
 */
trait NetworkControls
{
    private const NETWORK = 'BeoDevice/networkSettings/';

    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function getNetwork(): NetworkState
    {
        $settings = $this->deviceApiClient()->getStrict(self::NETWORK)['profile']['networkSettings'] ?? throw new \RuntimeException('The device did not report its network settings.');
        $known = $this->deviceApiClient()->getStrict(self::NETWORK.'wireless/networks')['networks'] ?? [];

        $wired = $settings['interfaces']['wired'] ?? null;
        $wireless = $settings['interfaces']['wireless'] ?? null;
        $active = $wireless['activeNetwork'] ?? [];
        $activeSsid = $active['ssid'] ?? null;

        $connections = [];
        if ($wired !== null) {
            $connections['wired'] = new NetworkConnection('wired', (string) ($wired['status'] ?? 'unknown'), isset($wired['dhcp']) ? (bool) $wired['dhcp'] : null,
                $wired['ipv4']['address'] ?? null, $wired['ipv4']['subnetMask'] ?? null, $wired['ipv4']['defaultGateway'] ?? null,
                $wired['ipv4']['preferredDns'] ?? null, $wired['ipv4']['alternativeDns'] ?? null);
        }
        if ($wireless !== null) {
            $connections['wireless'] = new NetworkConnection('wireless', (string) ($wireless['status'] ?? 'unknown'), isset($active['dhcp']) ? (bool) $active['dhcp'] : null,
                $active['ipv4']['address'] ?? null, $active['ipv4']['subnetMask'] ?? null, $active['ipv4']['defaultGateway'] ?? null,
                $active['ipv4']['preferredDns'] ?? null, $active['ipv4']['alternativeDns'] ?? null,
                $activeSsid, $active['frequency'] ?? null, $active['quality'] ?? null, isset($active['rssi']) ? (int) $active['rssi'] : null, $active['encryption'] ?? null);
        }

        // The passphrase of a network is never read into a value object.
        $networks = array_map(fn (array $network) => new WifiNetwork(
            (string) ($network['ssid'] ?? ''),
            $network['frequency'] ?? null,
            $network['encryption'] ?? null,
            (bool) ($network['configured'] ?? true),
            $activeSsid !== null && ($network['ssid'] ?? null) === $activeSsid,
        ), $known);

        return new NetworkState(
            $settings['activeInterface'] ?? null,
            array_values(array_diff($settings['_capabilities']['value']['activeInterface'] ?? [], ['none'])),
            (bool) ($settings['internetReachable'] ?? false),
            $connections,
            $networks,
        );
    }

    public function setActiveInterface(string $interface): void
    {
        $state = $this->getNetwork();

        // `none` would cut the device off; it is deliberately not offered.
        if (!in_array($interface, $state->interfaces, true)) {
            throw new \InvalidArgumentException("The device cannot use '{$interface}' (offered: ".implode(', ', $state->interfaces).').');
        }
        if ($interface === 'wireless' && !array_filter($state->wifiNetworks, fn (WifiNetwork $n) => $n->configured)) {
            throw new \InvalidArgumentException('No Wi-Fi network is set up on this device, so it would lose its connection. Join a network first.');
        }

        $this->deviceApiClient()->putStrict(self::NETWORK, ['profile' => ['networkSettings' => ['activeInterface' => $interface]]]);
    }

    public function setWiredAddress(bool $dhcp, array $ipv4 = []): void
    {
        $body = ['dhcp' => $dhcp];

        if (!$dhcp) {
            $address = $this->ipv4Field($ipv4, 'address', true);
            $mask = $this->ipv4Field($ipv4, 'subnet_mask', true);
            $gateway = $this->ipv4Field($ipv4, 'gateway', true);

            $maskBits = ip2long($mask);
            $contiguous = $maskBits !== false && (($maskBits | ($maskBits - 1)) & 0xFFFFFFFF) === 0xFFFFFFFF;
            if (!$contiguous || $maskBits === 0) {
                throw new \InvalidArgumentException("'{$mask}' is not a valid subnet mask.");
            }
            if ((ip2long($address) & $maskBits) !== (ip2long($gateway) & $maskBits)) {
                throw new \InvalidArgumentException('The gateway is not in the same subnet as the address.');
            }

            $body['ipv4'] = [
                'address' => $address,
                'subnetMask' => $mask,
                'defaultGateway' => $gateway,
                'preferredDns' => $this->ipv4Field($ipv4, 'preferred_dns', false) ?? '',
                'alternativeDns' => $this->ipv4Field($ipv4, 'alternative_dns', false) ?? '',
            ];
        }

        $this->deviceApiClient()->putStrict(self::NETWORK.'wired', ['wired' => $body]);
    }

    public function joinWifi(string $ssid, ?string $passphrase = null, ?string $security = null): void
    {
        if ($ssid === '' || strlen($ssid) > 32) {
            throw new \InvalidArgumentException('A Wi-Fi network name is 1 to 32 characters.');
        }
        if ($passphrase !== null && $passphrase !== '' && (strlen($passphrase) < 8 || strlen($passphrase) > 63)) {
            throw new \InvalidArgumentException('A Wi-Fi passphrase is 8 to 63 characters.');
        }
        if ($security !== null && !preg_match('/^[A-Za-z0-9]+$/', $security)) {
            throw new \InvalidArgumentException('Unknown security type.');
        }

        $network = array_filter([
            'ssid' => $ssid,
            'passphrase' => $passphrase,
            'encryption' => $security,
        ], fn ($value) => $value !== null && $value !== '') + ['dhcp' => true, 'configured' => true];

        $this->deviceApiClient()->putStrict(self::NETWORK.'wireless', ['wireless' => ['activeNetwork' => $network]]);
    }

    private function ipv4Field(array $ipv4, string $key, bool $required): ?string
    {
        $value = $ipv4[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw new \InvalidArgumentException("A static address needs {$key}.");
            }

            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException("'{$value}' is not a valid IPv4 address for {$key}.");
        }

        return $value;
    }
}
