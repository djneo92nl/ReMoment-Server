<?php

namespace App\Integrations\Contracts;

/**
 * The host side of multiroom: this device's active experience is expanded to another device, by that
 * device's multiroom ID. A guest joins a host by asking the host to do this (ASE's listenerList,
 * Mozart's beolink/expand) — the other way round, a device pointing itself at the host, makes it a host.
 */
interface SessionHostInterface
{
    public function addListener(string $peerId): void;
}
