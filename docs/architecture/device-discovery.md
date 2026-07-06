# Device Discovery

ReMomentServer can automatically discover devices on the local network via brand-specific `DiscoveryInterface` implementations. Discovery commands create or update `Device` records in the database; they never delete devices.

## Discovery Commands

### UPnP / SSDP (Bang & Olufsen ASE)

```bash
php artisan device:discovery
```

Uses `AseDiscovery` to send an SSDP M-SEARCH multicast to `239.255.255.250:1900` for `urn:schemas-upnp-org:device:MediaRenderer:1`, fetches each responding device's UPnP XML descriptor, and resolves the driver from `config/devices.php` by manufacturer + model. Matches existing devices by the `upnp_uuid` key in `device_meta`; otherwise creates a new `Device`.

### Bang & Olufsen Mozart Platform

```bash
php artisan device:additional-bo-device-discovery
```

Uses `MozartDiscoveryService` (mDNS) to find newer B&O devices on the Mozart platform that don't appear in standard UPnP scans. Matches existing devices by the `jid` key in `device_meta`, falling back to IP address. Only adds/updates devices — it does not remove stale ones.

### Sonos

```bash
php artisan device:sonos-discovery
```

Uses `DeviceDiscoveryService` (backed by the `duncan3dc/sonos` library) to find Sonos devices on the network and persist them.

## Starting Device Listeners

After devices are registered, start the listener processes that stream real-time state from them:

```bash
php artisan device:listen
```

Spawns one background process per device (mapped by driver class — currently ASE via `device-ase:listen-single {id}`), plus a Spotify listener (`device-spotify:listen`) if a Spotify account is connected. It monitors and automatically restarts any listener process that exits. This is the command used by `composer dev` / production process management; it runs until interrupted (Ctrl+C).

To run a single ASE device's listener directly:

```bash
php artisan device-ase:listen-single {deviceId}
```

## Device Metadata

Beyond the core `devices` table columns, per-device identifiers are stored in `device_meta` as key-value pairs:

| Key | Source | Description |
|-----|--------|-------------|
| `upnp_uuid` | UPnP/SSDP discovery | Unique device identifier from UPnP |
| `jid` | Mozart mDNS discovery | Jabber ID for B&O Mozart platform devices |
| `ase_jid` | `MultiRoomInterface::getMultiRoomId()` | B&O JID used for multiroom peer lookup |
| `sonos_uuid` | `MultiRoomInterface::getMultiRoomId()` | Sonos UUID used for multiroom peer lookup |
| `spotify_connect_name` | Manual (Settings UI) | Maps a Spotify Connect speaker name to a local device |

Run `php artisan devices:sync-sources` after discovery to populate sources and multiroom JIDs for all capable devices (see CLAUDE.md's "Multiroom / Device Joining" section).

## Manual Device Registration

Devices can be added through the web UI at `/devices/create` — the form presents brand and product as selectable options populated from `config/devices.php` and auto-fills the driver class.

The `device_brand_name` + `device_product_type` combination must match an entry in `config/devices.php` for the driver to resolve correctly.
