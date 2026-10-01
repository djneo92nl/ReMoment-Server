# Discovery for Client Devices (mDNS)

How client devices (the ESP32 touch remote, Pis, software clients) find the ReMoment server on the LAN without a hardcoded IP. This is the reverse of `device-discovery.md`, which is about the server finding speakers.

---

## What is advertised

| | |
|---|---|
| Service type | `_remoment._tcp` |
| Instance name | `ReMoment on {hostname}` |
| Port | the HTTP port (`app_port`, default `80`) |
| TXT `path` | `/api` — API base path on that host/port |
| TXT `api_version` | `1` — client contract version, same as `api_version` in `GET /api/info` |

The host itself is also reachable as `{hostname}.local`, which Avahi publishes by default.

TXT is deliberately minimal. Everything else (broker address, artwork base URL, server release version) comes from `GET {host}:{port}{path}/info` — see `docs/api/server-info.md` — so it can't go stale in the mDNS record.

---

## Decision: Avahi on the host, deployed by Ansible

The app runs in Docker on a bridge network (`compose.yaml`, `docker-compose.prod.yml`). Options considered:

| Option | Verdict |
|--------|---------|
| Responder in PHP inside the app container | No maintained PHP mDNS *responder* (`clue/mdns-react`, already a dependency, only resolves). Multicast from a bridge network doesn't reach the LAN reliably, and a long-running PHP responder would be one more process to supervise. |
| Avahi container with `network_mode: host` | Works, but needs host networking plus usually the host's D-Bus socket or must conflict with a host Avahi already bound to 5353. More moving parts than the host package. |
| `network_mode: host` for the app | Breaks the compose network (redis/meilisearch/mosquitto service names). |
| **Avahi on the host + a static service file** | Chosen. Ubuntu ships `avahi-daemon`; a service file in `/etc/avahi/services/` is the whole implementation. Nothing in the app, no new dependencies, survives container restarts, and the advertised port matches the port Docker publishes. |

The trade-off: the advertisement is static. It keeps announcing while the app container is down. Clients must handle a failing `GET /api/info` anyway (retry with backoff), so that is acceptable.

---

## Setup

### Production (Ansible)

`ansible/setup.yml` (run with `--ask-become-pass`, safe to re-run):

1. installs `avahi-daemon`
2. renders `ansible/templates/remoment.service.j2` to `/etc/avahi/services/remoment.service` (port from `app_port`, `api_version` from `remoment_api_version` in `ansible/group_vars/all.yml`)
3. enables/starts Avahi and restarts it when the file changes

Re-run `setup.yml` after changing `app_port` or bumping `remoment_api_version`. When bumping, also bump `InfoController::API_VERSION`.

If the host runs a firewall, allow UDP 5353 (mDNS) in addition to the HTTP and MQTT ports.

### Manual (non-Ansible Linux host)

```bash
sudo apt install avahi-daemon
sudo cp remoment.service /etc/avahi/services/   # rendered template, port filled in
sudo systemctl restart avahi-daemon
```

### Local development (macOS / Sail)

macOS has mDNS built in. While Sail is running, advertise it from a terminal (stops on Ctrl+C):

```bash
dns-sd -R "ReMoment dev" _remoment._tcp local 80 path=/api api_version=1
```

### Verifying

```bash
avahi-browse -rt _remoment._tcp      # Linux
dns-sd -B _remoment._tcp             # macOS (then dns-sd -L "ReMoment on host" _remoment._tcp)
```

---

## Client side

```
MDNS.queryService("remoment", "tcp")        // ESP32 Arduino: ESPmDNS
  → pick first result: IP, port, TXT path (default "/api"), TXT api_version
  → GET http://{IP}:{port}{path}/info
  → continue with docs/api/server-info.md
```

Use the resolved IP rather than the `.local` name for HTTP and MQTT: `HTTPClient` / `PubSubClient` on ESP32 don't resolve `.local` names themselves. Cache the last working address in NVS and fall back to it (and to a manually configured IP) when no service is found — mDNS can be blocked on some networks (guest Wi-Fi, client isolation, VLANs).
