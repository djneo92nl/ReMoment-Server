<?php

namespace App\Integrations\Contracts;

use App\Models\Device;
use App\Models\MediaServer;

/**
 * A media server (Jellyfin; Plex, Emby and Kodi later) behind one row of `media_servers`. What it can do beyond this is
 * declared by implementing the capability interfaces below; `App\Domain\MediaServers\MediaServerCapabilities` turns
 * them into the capability strings. Add a type: a driver class, an entry in config/media-servers.php, a credentials
 * declaration and a probe, nothing in the API, the card or the MCP tool (docs/architecture/media-servers.md).
 */
interface MediaServerDriverInterface
{
    public function __construct(MediaServer $server);

    /** The credentials are complete: without them the server is left out everywhere. */
    public function configured(): bool;

    /** Whether a device is the kind of client this server can show shelves on and start items on (a Philips Android TV for Jellyfin). */
    public function canPlayOn(Device $device): bool;
}
