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
php artisan device:sonos-discovery [--host=192.168.1.9 ...]
```

`SonosDiscovery` does not depend on multicast alone. Seeds are the `--host` / "Known device IPs" input, the IPs of Sonos devices already stored, and an SSDP search (`ZonePlayer:1`). The first seed that answers on port 1400 lists the whole household through `ZoneGroupTopology` (`SonosTopology`), so one reachable speaker finds the rest. Stereo-pair secondaries, satellites and Boost bridges are skipped. Devices match on `sonos_uuid`, then on IP; an IP change is written back. Notes on what failed (e.g. "no multicast replies") come back from `diagnostics()` and are shown on the CLI and in the Scan page.

## Docker and multicast

SSDP and mDNS multicast do not cross a Docker bridge. `docker-compose.prod.yml` therefore runs `app` with `network_mode: host` (Linux: LXC, Raspberry Pi); Redis, Meilisearch and Mosquitto publish on loopback and the app reaches them on `127.0.0.1` (`REDIS_HOST`, `MQTT_HOST` in `ansible/templates/env.j2`). `APP_PORT` sets the `artisan serve` port. Local Sail on macOS cannot do this; use the "Known device IPs" field there. `App\Services\Discovery\SsdpClient` sends the M-SEARCH three times from every local IPv4 interface and is shared by the ASE and DLNA discoverers. Mozart mDNS still uses its own socket code.

## Starting Device Listeners

After devices are registered, start the listener processes that stream real-time state from them:

```bash
php artisan device:listen
```

Spawns one background process per device (mapped by driver class: ASE via `device-ase:listen-single {id}`, Mozart via `device-mozart:listen-single {id}`, Sonos via `device-sonos:listen-single {id}`), plus a Spotify listener (`device-spotify:listen`) if a Spotify account is connected. It monitors and automatically restarts any listener process that exits. This is the command used by `composer dev` / production process management; it runs until interrupted (Ctrl+C).

The Sonos listener polls the speaker once a second by its stored IP (unicast, no discovery), reporting now-playing, progress, volume and shuffle/repeat; stopped maps to `standby`, paused to `paused`.

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
