# Device Discovery

ReMomentServer can automatically discover devices on the local network via brand-specific `DiscoveryInterface` implementations. Discovery commands create or update `Device` records in the database; they never delete devices.

## Discovery command

```bash
php artisan device:discover [--brand=sonos|ase|mozart ...] [--host=192.168.1.9 ...]
```

One command for every brand. It runs the discoverers registered under `discoverers` in `config/devices.php` (`--brand` keeps those whose class name contains the given text), passes each `--host` to discoverers that implement `HostHintedDiscovery`, and stores everything found through `App\Domain\Device\DiscoveredDevicePersister`: a device is matched on any identifier in `DiscoveredDevice::$meta` (`upnp_uuid` for ASE and Mozart, `sonos_uuid` for Sonos), else on its IP **within the same brand** (DHCP reuses IPs, a new brand never takes over an old device); it is then updated (an IP change is written back) or created, and its meta keys are upserted. Devices are never deleted. Discoverer notes (e.g. "no multicast replies") are printed after the list. The web "Scan" page uses the same discoverers through `NetworkDiscoveryService`, only listing devices not yet stored.

A new brand needs a `DiscoveryInterface` implementation in `config('devices.discoverers')`, nothing else.

- **ASE** (`AseDiscovery`) and **Mozart** (`Remoment\MozartDriver\MozartDiscovery`) share `App\Integrations\Common\UpnpMediaRendererDiscovery`: an SSDP M-SEARCH (`SsdpClient`) for `urn:schemas-upnp-org:device:MediaRenderer:1`, then each responder's UPnP XML descriptor is fetched and the driver resolved from `config/devices.php` by manufacturer + model. Each subclass only names its `driver_name` (`ASE` / `Mozart`), so it reports just its own platform's models.
- **Sonos** (`SonosDiscovery`): does not depend on multicast alone. Seeds are the `--host` / "Known device IPs" input, the IPs of Sonos devices already stored, and an SSDP search (`ZonePlayer:1`). The first seed that answers on port 1400 lists the whole household through `ZoneGroupTopology` (`SonosTopology`), so one reachable speaker finds the rest. Stereo-pair secondaries, satellites and Boost bridges are skipped.

## Docker and multicast

SSDP and mDNS multicast do not cross a Docker bridge. `docker-compose.prod.yml` therefore runs `app` with `network_mode: host` (Linux: LXC, Raspberry Pi); Redis, Meilisearch and Mosquitto publish on loopback and the app reaches them on `127.0.0.1` (`REDIS_HOST`, `MQTT_HOST` in `ansible/templates/env.j2`). `APP_PORT` sets the `artisan serve` port. Local Sail on macOS cannot do this; use the "Known device IPs" field there. `App\Services\Discovery\SsdpClient` sends the M-SEARCH three times from every local IPv4 interface and is shared by the ASE, Mozart and DLNA discoverers.

## Starting Device Listeners

After devices are registered, start the listener processes that stream real-time state from them:

```bash
php artisan device:listen
```

One supervisor process boots the application once and **forks one child per device**, so each listener shares the booted framework copy-on-write instead of paying for its own `php artisan` (~50 MB each), which is what keeps a dozen listeners within a Raspberry Pi's memory. It needs the `pcntl` extension (part of php-cli on Linux and macOS). Which listener a device gets comes from the driver map `listeners` in `config/devices.php`; a listener may decline (Spotify, until an account is connected).

The supervisor:

- restarts a child that exits, with a delay that doubles from 1 s to 30 s while it keeps failing;
- every `--rescan` seconds (default 15) starts listeners for devices added since, stops those of deleted devices, and restarts one whose IP changed, so adding a device or connecting Spotify needs no restart;
- on SIGINT/SIGTERM signals all children at once and gives them 5 s before SIGKILL (a child blocked in a network call only sees the signal when the call returns).

Run it under process management in production; it runs until interrupted (Ctrl+C).

### Adding a listener for a new integration

Implement `App\Integrations\Contracts\DeviceListenerInterface` (`forDevice(Device): ?static`, `listen(string $deviceId)`), fire the events from `AppServiceProvider` from it, and add `DriverClass::class => ListenerClass::class` under `listeners` in `config/devices.php`. Nothing else changes.

The Sonos listener polls the speaker once a second by its stored IP (unicast, no discovery), reporting now-playing, progress, volume and shuffle/repeat; stopped maps to `standby`, paused to `paused`.

To run one device's listener directly (any brand), e.g. to watch its output while debugging:

```bash
php artisan device:listen-single {deviceId}
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

Run `php artisan device:sync-sources` after discovery to populate sources and multiroom JIDs for all capable devices (see CLAUDE.md's "Multiroom / Device Joining" section).

## Manual Device Registration

Devices can be added through the web UI at `/devices/create` — the form presents brand and product as selectable options populated from `config/devices.php` and auto-fills the driver class.

The `device_brand_name` + `device_product_type` combination must match an entry in `config/devices.php` for the driver to resolve correctly.
