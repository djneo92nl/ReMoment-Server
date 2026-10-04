<?php

namespace App\Domain\Device\Settings;

final readonly class NetworkState
{
    /**
     * @param  string[]  $interfaces  what `active_interface` may be set to
     * @param  NetworkConnection[]  $connections  keyed by type (wired, wireless)
     * @param  WifiNetwork[]  $wifiNetworks  networks the device knows (it has no scan)
     */
    public function __construct(
        public ?string $activeInterface,
        public array $interfaces,
        public bool $internetReachable,
        public array $connections,
        public array $wifiNetworks,
    ) {}

    public function connection(string $type): ?NetworkConnection
    {
        return $this->connections[$type] ?? null;
    }

    public function toArray(): array
    {
        return [
            'active_interface' => $this->activeInterface,
            'interfaces' => $this->interfaces,
            'internet_reachable' => $this->internetReachable,
            'wired' => $this->connection('wired')?->toArray(),
            'wireless' => $this->connection('wireless')?->toArray(),
            'wifi_networks' => array_map(fn (WifiNetwork $n) => $n->toArray(), $this->wifiNetworks),
        ];
    }
}
