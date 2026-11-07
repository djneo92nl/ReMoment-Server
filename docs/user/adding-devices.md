# Adding devices

Go to `/settings/devices` (the setup wizard links there). *Scan* runs discovery and lists what it finds; nothing is added by itself. Per row: rename, **Add** or **Ignore**. The same scan runs hourly in the background (`DEVICE_AUTO_DISCOVER=false` turns that off). A device that discovery cannot see is added by IP at the bottom of the same page (the "Known device IPs" field helps discovery when scanning is blocked) or at `/devices/create`, where you pick brand and product. `php artisan device:discover [--brand=…] [--host=IP]` does the same from the command line. Discovery details: [device-discovery.md](../architecture/device-discovery.md).

Which models are confirmed to work: see [hardware.md](hardware.md) (a placeholder for now). Until then, this page says what the repository's own docs record.

Discovery uses SSDP and mDNS multicast. If the server is in Docker without host networking, or on another VLAN than the devices, scanning finds nothing: [troubleshooting.md](troubleshooting.md).

## Bang & Olufsen (ASE and Mozart)

- **ASE** products (BeoSound Essence and Moment, Beoplay M3 and M5, the Beoplay V1 TV box): found by an SSDP search for UPnP MediaRenderers. Mozart-platform products (found the same way) use a separate driver.
- A **BeoLink Converter NL/ML** is not a MediaRenderer and is not announced: add it with `php artisan device:discover --brand=converter --host=192.168.1.10`.
- Multiroom between B&O devices needs their ids first; `php artisan device:sync-sources` fills them in.
- Status: the ASE driver is developed against devices in the author's lab. The Mozart driver was built from B&O's official OpenAPI spec without hardware at first; its checklist for real devices is [mozart-hardware-test.md](../architecture/mozart-hardware-test.md). Treat Mozart as partly unverified.

## Sonos

Found by SSDP, then by Sonos topology: one reachable speaker lists the whole household. If scanning is blocked, enter one speaker's IP in "Known device IPs". Sonos is polled over its local network interface, no cloud account is involved. Stereo-pair secondaries, satellites and Boost bridges are skipped.

## Generic UPnP / DLNA players

Any audio MediaRenderer without its own driver (hi-fi streamers, Kodi, foobar2000, and so on) is added with the generic UPnP driver: play, pause, stop, volume and seek where the device lists them, plus a queue kept by the server. No multiroom, sources or power ([upnp-renderer.md](../architecture/upnp-renderer.md)). A renderer that answers no multicast can be given by IP, or by the URL of its description file (for example `http://192.168.1.105:49153/nmrDescription.xml`) in "Known device IPs". The Philips Streamium MCi900 was verified this way ([philips-streamium.md](../architecture/philips-streamium.md)). Other brands are *untested*; a WiiM player is built from documentation only.

## Philips Android TV

The TV is first added as a generic UPnP device. To get standby, its apps as sources and the app in front as now playing, pair it:

1. Switch the TV on.
2. Run, with the id of the TV from `/devices/{id}` (the Docker stack from [install-docker.md](install-docker.md)):
   ```bash
   docker compose -f docker-compose.prod.yml exec -u sail app php artisan philips:pair 12
   ```
3. The TV shows a PIN; type it into the command. The device listener rescans every 15 seconds and switches a paired TV to the new driver by itself; if the card does not change, restart the app container.

Pairing, apps as sources and standby are confirmed on a real TV; every remote key, every kind of app and waking the TV from full sleep are not. Switching HDMI inputs is not offered. Details and a list of what is unverified: [philips-tv.md](../architecture/philips-tv.md). Showing Jellyfin on the TV needs a Jellyfin API key at `/settings/services` (unverified against a real Jellyfin server).

## Spotify

Spotify is a virtual device, not a speaker. Enter your own Spotify app's Client ID and secret at `/settings/services` (every install brings its own; steps in [spotify-app-setup.md](../architecture/spotify-app-setup.md); Spotify needs a Premium account for the app owner and allows 5 users per Client ID), then connect at `/settings/spotify`. To make a speaker behave as the Spotify player, map its Spotify Connect name at `/settings/spotify-connect`. For speakers without Spotify of their own there is an optional librespot stream ([spotify-stream.md](../architecture/spotify-stream.md)); it needs the `spotify-stream` compose profile and a Linux host.

## Client devices (wall panels, apps)

These are not players. They self-register and you approve them at `/settings/clients`; see [first-run.md](first-run.md#the-setup-wizard-setup).
