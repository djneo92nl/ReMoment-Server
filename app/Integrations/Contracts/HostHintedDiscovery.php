<?php

namespace App\Integrations\Contracts;

/** A discoverer that can also probe given hosts directly, and explain what it tried. */
interface HostHintedDiscovery extends DiscoveryInterface
{
    /** Hosts (IPs) to probe by unicast, for networks where multicast does not reach us. */
    public function withHosts(array $hosts): static;

    /** @return string[] Human-readable notes on the last discover() call. */
    public function diagnostics(): array;
}
