<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\Settings\NetworkState;

interface NetworkSettingsInterface
{
    public function getNetwork(): NetworkState;

    /**
     * Switch the interface the device uses. Throws InvalidArgumentException
     * for a value the device doesn't offer, or one that would leave it
     * without a connection (wireless with no known network).
     */
    public function setActiveInterface(string $interface): void;

    /**
     * DHCP, or a static address; $ipv4 keys: address, subnet_mask, gateway,
     * preferred_dns, alternative_dns. Throws InvalidArgumentException for
     * a static address that is incomplete or not a valid IPv4 setup.
     *
     * @param  array<string, string|null>  $ipv4
     */
    public function setWiredAddress(bool $dhcp, array $ipv4 = []): void;

    /** Connect to a Wi-Fi network; $security is the device's own value (e.g. wpa2PskTkip), null to leave it to the device. */
    public function joinWifi(string $ssid, ?string $passphrase = null, ?string $security = null): void;
}
