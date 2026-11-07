# Troubleshooting

Start with `/settings/health` (admin login): it shows the MQTT broker, the scheduler and queue-worker heartbeats, per-device listeners, external services (Spotify, MusicBrainz and others, with a *Check now* button), pending and failed jobs, and active alerts.

## Logs

Commands below assume the stack of [install-docker.md](install-docker.md).

```bash
docker compose -f docker-compose.prod.yml logs -f app          # supervisor programs write to the container output
docker compose -f docker-compose.prod.yml ps
tail -f storage/logs/laravel.log                                # in the checkout (other *.log files may sit next to it)
```

`LOG_LEVEL=warning` (the production default of the Ansible template) hides most detail; raise it to `debug` in `.env` and restart while investigating. A diagnostics bundle download is planned and not built.

## My device is not found

Discovery uses SSDP and mDNS multicast (UDP), which only reach devices on the **same network segment**.

- **Docker bridge network.** Multicast does not cross a Docker bridge. `docker-compose.prod.yml` runs the app with `network_mode: host` for this reason, on Linux only. With `compose.yaml` (the developer stack) or Docker Desktop on macOS or Windows, scanning will not find devices: enter IPs under "Known device IPs" at `/settings/devices` (this sends unicast searches, which work from inside a bridge network).
- **Another VLAN or subnet.** Routers do not forward multicast between VLANs by default. Either put the server on the devices' VLAN, enable an mDNS/SSDP reflector on your router or firewall (for example Avahi's reflector; not covered here), or add devices by IP.
- **Firewall on the server.** Allow outgoing multicast and the replies (UDP).
- **Not every device announces itself.** The BeoLink Converter and some UPnP renderers need `--host` / their description URL, see [adding-devices.md](adding-devices.md).
- Check the device is on and reachable: `ping`, and open its IP in a browser.

## Playback fails

- **Own files or library tracks: the speaker says it cannot play the file.** Speakers fetch the file from the server by URL. `STREAM_BASE_URL` (or `APP_URL` when empty) must be the server's address as the speaker sees it. A stale address, for example after the server got a new DHCP address, or a `.local` name the speaker cannot resolve, shows up as a refusal with **no request in the server log**. Use the IP address and reserve it in your router.
- **`422 unsupported`** from the API means the device lacks that capability; check its `capabilities` in `GET /api/devices`. **`503`**: the device is unreachable. **`502`**: the device or service rejected the command.
- **A device shows `unreachable`.** Check the listener on `/settings/health`. After an IP change run `php artisan device:discover` (devices are matched on their UPnP or Sonos id) or edit the IP at `/devices/{id}/edit`.
- **Spotify.** Needs your own app credentials and a connected account (`/settings/services`, `/settings/spotify`); `/settings/health` reports a missing app.
- **A device plays but formats are wrong** (high-resolution files on an old streamer): some drivers transcode through ffmpeg; see [file-storage.md](../architecture/file-storage.md).

## Nothing updates live

The web UI gets pushes from the Mosquitto broker over **MQTT-over-WebSocket on port 9001**. If the browser cannot reach it, pages fall back to polling every second (`/receiver`: every 3 seconds), so everything still works, just heavier.

- Port 9001 must be open from your browser to the server (`docker compose ... ps` should show mosquitto publishing `9001`).
- Behind HTTPS a `ws://` broker is blocked by the browser. Proxy a `wss://` endpoint and set `MQTT_WS_URL` to it: [reverse-proxy.md](reverse-proxy.md).
- `MQTT_WS_URL` and `MQTT_PUBLIC_WS_PORT` in `.env` control what the browser is told; empty means "page host, port 9001".
- Wall panels and apps read the MQTT address from `GET /api/info` (`mqtt.host`; `MQTT_PUBLIC_HOST` overrides the host if the request host is not right).

## "This is not part of your profile"

A page that redirects to `/not-in-profile/…`, or an API call that answers `422 not_available_in_profile`, needs a bigger profile than the one running, for example the library under Control. Set `PROFILE` in `.env` (and `COMPOSE_PROFILES`) and restart, or use `php artisan profile:upgrade` to move Control to Standard or Full. See [README.md](README.md#profiles).

## Other

- **Jobs stuck, enrichment never finishes:** the Queue tab at `/settings/health` shows pending and failed jobs and can restart the workers.
- **Admin pages ask you to log in:** see [first-run.md](first-run.md#the-admin-login).
- **Backup warning on the health page:** [upgrading-and-backups.md](upgrading-and-backups.md).
