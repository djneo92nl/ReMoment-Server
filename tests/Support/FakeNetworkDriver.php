<?php

namespace Tests\Support;

use App\Domain\Device\Settings\NetworkConnection;
use App\Domain\Device\Settings\NetworkState;
use App\Domain\Device\Settings\WifiNetwork;
use App\Domain\Device\Settings\WirelessSpeaker;
use App\Domain\Device\Settings\WirelessSpeakersState;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\NetworkSettingsInterface;
use App\Integrations\Contracts\WirelessSpeakersInterface;
use App\Models\Device;

/** Bound as a device's `device_driver` in network / wireless speaker tests. */
class FakeNetworkDriver implements MusicPlayerDriverInterface, NetworkSettingsInterface, WirelessSpeakersInterface
{
    /** @var array<int, array> */
    public static array $calls = [];

    public static ?\Exception $throw = null;

    public static bool $scanning = false;

    public static string $active = 'wired';

    /** @var WifiNetwork[] */
    public static array $networks = [];

    public function __construct(public Device $device) {}

    public static function reset(): void
    {
        self::$calls = [];
        self::$throw = null;
        self::$scanning = false;
        self::$active = 'wired';
        self::$networks = [new WifiNetwork('HomeNet', '5ghz', 'wpa2PskTkip', true, false)];
    }

    public function getCurrentPlayingAttribute(): array
    {
        return [];
    }

    public function getNetwork(): NetworkState
    {
        $this->failIfAsked();

        return new NetworkState(
            self::$active,
            ['wired', 'wireless'],
            true,
            [
                'wired' => new NetworkConnection('wired', 'connected', true, '192.168.1.25', '255.255.255.0', '192.168.1.1', '192.168.1.28', ''),
                'wireless' => new NetworkConnection('wireless', 'inactive'),
            ],
            self::$networks,
        );
    }

    public function setActiveInterface(string $interface): void
    {
        $this->failIfAsked();
        if (!in_array($interface, ['wired', 'wireless'], true)) {
            throw new \InvalidArgumentException("The device cannot use '{$interface}'.");
        }

        self::$calls[] = ['setActiveInterface', $interface];
    }

    public function setWiredAddress(bool $dhcp, array $ipv4 = []): void
    {
        $this->failIfAsked();
        if (!$dhcp && empty($ipv4['address'])) {
            throw new \InvalidArgumentException('A static address needs address.');
        }

        self::$calls[] = ['setWiredAddress', $dhcp, $ipv4];
    }

    public function joinWifi(string $ssid, ?string $passphrase = null, ?string $security = null): void
    {
        $this->failIfAsked();
        self::$calls[] = ['joinWifi', $ssid, $passphrase, $security];
    }

    public function getWirelessSpeakers(): WirelessSpeakersState
    {
        $this->failIfAsked();

        return new WirelessSpeakersState(self::$scanning ? 'scanning' : 'notInitiated', self::$scanning, self::$scanning ? [new WirelessSpeaker('spk-1', 'Front Left', 'found')] : []);
    }

    public function startWirelessScan(): void
    {
        $this->failIfAsked();
        self::$calls[] = ['startWirelessScan'];
        self::$scanning = true;
    }

    public function stopWirelessScan(): void
    {
        $this->failIfAsked();
        self::$calls[] = ['stopWirelessScan'];
        self::$scanning = false;
    }

    private function failIfAsked(): void
    {
        if (self::$throw) {
            throw self::$throw;
        }
    }
}
