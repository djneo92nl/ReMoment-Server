<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('ReMoment Server')]
#[Version('1.0.0')]
#[Instructions(<<<'TXT'
Controls networked audio devices (Bang & Olufsen, Sonos, Spotify) and manages their shared music library.

Start with list_devices to find a device id, and read its `capabilities` before using a feature: a missing capability answers `unsupported`. States: playing, paused, standby, unreachable (control calls then fail with `unreachable`). While Spotify plays on a mapped speaker, the speaker and Spotify behave as one device (the speaker's id).

To play something: search_library or browse_library for an id, then play_from_library on a device; radio stations and sources have their own tools. Artist/album/track ids can disappear when duplicates are merged.

Tools marked ADMIN are only listed (and only work) when this MCP connection carries an admin bearer token (Authorization: Bearer rmt_…, from POST /api/admin/login). Destructive actions ask for confirm=true; do a dry_run first where offered.
TXT)]
class ReMomentServer extends Server
{
    protected array $tools = [
        Tools\ListDevices::class,
        Tools\GetDevice::class,
        Tools\PlaybackControl::class,
        Tools\Volume::class,
        Tools\Queue::class,
        Tools\Sources::class,
        Tools\Radio::class,
        Tools\Multiroom::class,
        Tools\DeviceSettings::class,
        Tools\SearchLibrary::class,
        Tools\BrowseLibrary::class,
        Tools\SetFavorite::class,
        Tools\PlayFromLibrary::class,
        Tools\ManagePlaylist::class,
        Tools\LibraryMaintenance::class,
        Tools\RemoveLibraryItem::class,
        Tools\Storage::class,
    ];
}
