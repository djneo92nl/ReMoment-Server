# Server Info (`GET /api/info`)

Bootstrap endpoint for client devices. A client that has found the server (via mDNS, see `docs/architecture/discovery-for-clients.md`, or a configured IP) calls this once to learn where everything else lives, instead of hardcoding broker addresses or URL layouts.

Unauthenticated, like the rest of the API. Controller: `app/Http/Controllers/Api/InfoController.php`.

---

## Response

```json
{
  "name": "ReMoment",
  "version": "dev",
  "api_version": 1,
  "base_url": "http://192.168.1.100",
  "api_base_url": "http://192.168.1.100/api",
  "artwork_base_url": "http://192.168.1.100",
  "mqtt": {
    "host": "192.168.1.100",
    "port": 1883,
    "topic_prefix": "remoment/player",
    "ws_port": 9001,
    "ws_url": "ws://192.168.1.100:9001"
  },
  "features": {
    "alerts": true, "scenes": true, "profile": "full", "deep_analysis": true, "analysis": true,
    "deep_analysis_kinds": ["sections", "stems"], "stems_weights": "ready", "discover": true, "similar_tracks": true,
    "export": true, "export_formats": ["mp3", "aac", "opus"], "rip": true, "media_servers": true, "sleep_timer": true,
    "nodes": true, "line_in": true, "sub_devices": true, "push": true, "ha_discovery": true
  },
  "placeholder": {
    "kind": "source",
    "proxy_512": "http://remoment.local/storage/artwork/6910ad50fb63f947ac24032558d3b570/512.jpg",
    "proxy_320": "http://remoment.local/storage/artwork/6910ad50fb63f947ac24032558d3b570/320.jpg",
    "proxy_120": "http://remoment.local/storage/artwork/6910ad50fb63f947ac24032558d3b570/120.jpg",
    "proxy_bg": "http://remoment.local/storage/artwork/6910ad50fb63f947ac24032558d3b570/bg_1024x600.jpg",
    "proxy_bg_320x480": "http://remoment.local/storage/artwork/6910ad50fb63f947ac24032558d3b570/bg_320x480.jpg",
    "proxy_bg_480x480": "http://remoment.local/storage/artwork/6910ad50fb63f947ac24032558d3b570/bg_480x480.jpg",
    "colors": ["#1b1b1b", "#e2e2e2", "#848484", "#5e5e5e", "#7c7c7c"],
    "safe_colors": ["#a6a6a6", "#e2e2e2", "#a6a6a6", "#a6a6a6", "#a6a6a6"]
  }
}
```

| Field | Source | Notes |
|-------|--------|-------|
| `name` | `APP_NAME` | Display name, e.g. for a "Connected to …" line |
| `version` | `config('app.version')`, read from the `VERSION` file at the repository root | Semantic version of the release (`1.0.0-dev` before 1.0); `0.0.0-unknown` if the file is missing. The same value is printed by `php artisan app:version` and recorded in the database (Setting `app_version`) by `app:version --record` |
| `api_version` | `InfoController::API_VERSION` | Integer, bumped only on breaking changes to the client contract. Firmware can refuse/warn on an unknown major |
| `base_url` | Request scheme + host (+ port) | Exactly how the client reached the server. Not `APP_URL`, which may be an mDNS name or reverse-proxy URL the client can't use |
| `api_base_url` | `base_url` + `/api` | Prefix for all REST endpoints |
| `artwork_base_url` | same as `base_url` | Prefix for artwork paths, see below |
| `mqtt.host` | `MQTT_PUBLIC_HOST`, else the request host | Broker host as the client should reach it |
| `mqtt.port` | `MQTT_PUBLIC_PORT`, else `MQTT_PORT`, else `1883` | Plain MQTT (TCP), not WebSockets |
| `mqtt.ws_port` | `MQTT_PUBLIC_WS_PORT`, default `9001` | The broker's WebSocket listener, for clients that speak MQTT over WebSocket (browsers, iOS apps) |
| `mqtt.ws_url` | `MQTT_WS_URL`, else `ws://{mqtt.host}:{ws_port}` (`wss://` when the request was HTTPS) | Ready-to-use WebSocket URL. Set `MQTT_WS_URL` when the broker sits behind a reverse proxy |
| `mqtt.topic_prefix` | constant | Device topics are `{topic_prefix}/{device_id}/{data,progress,state,volume,modes}` |
| `features` | `App\Domain\System\ServerFeatures` | What this server can do beyond the base contract; the table below lists every key. A client treats a **missing key as false**, so keys can be added without bumping `api_version` |
| `placeholder` | `NowPlayingArtwork::placeholder()` | Artwork object (same shape as `now_playing.artwork`, `kind: "source"`) of the generic music-note logo. Show it whenever there is no artwork, including the first play of a cover that is still being processed. Rendered on the spot if not cached, so it is always present (`null` only if rendering itself fails). Its hash is the same on every server; cache `proxy_320` (and your screen's background, see below) like any cover |

### `features`

One class builds it, `App\Domain\System\ServerFeatures::all()` (workstream 8.7). A key is there (true) only when the server really has the capability switched on **and** the profile allows it. Only `profile` is a string, `analysis` and `deep_analysis` are always present (an empty Sound page needs an explanation), everything else is **present only when true**. Every check is cheap (config, a setting, a row, or a value cached for a minute or less), so the endpoint never waits on a speaker or a sidecar that is down.

| Key | Present when | Where to read more |
|-----|--------------|--------------------|
| `profile` | always: `control`, `standard` or `full`. Endpoints of a feature the profile lacks answer `422 not_available_in_profile` | [profiles.md](../architecture/profiles.md) |
| `alerts` | always (`GET /api/alerts`) | [alerts.md](../architecture/alerts.md) |
| `scenes` | the profile has scenes (all of them today) | [scenes.md](scenes.md) |
| `analysis` | always present, boolean: audio analysis of the own files is enabled (`ANALYSIS_ENABLED`) and the profile allows it (Full) | [audio-analysis.md](../architecture/audio-analysis.md) |
| `deep_analysis` | always present, boolean: a deep-analysis service answers its health check (cached a minute) | [deep-analysis.md](deep-analysis.md) |
| `deep_analysis_kinds` | not empty: the kinds that can run now (`sections`, `stems`, `stem_audio`) | [deep-analysis.md](deep-analysis.md) |
| `stems_weights` | a stems service is configured in a profile with deep analysis: `missing`, `downloading`, `ready` or `failed`, the state of the opt-in Demucs download (cached 30 s; `stems` and `stem_audio` appear in `deep_analysis_kinds` only at `ready`). Absent when the service cannot be asked | [stems.md](stems.md) |
| `discover` | the profile has the library (`GET /api/library/discover`) | [discover.md](discover.md) |
| `similar_tracks` | at least one own file has a sound embedding (Full) | [similar-tracks.md](similar-tracks.md) |
| `export`, `export_formats` | the profile has the library and own files and ffmpeg can transcode; `export_formats` lists what ffmpeg can make (`mp3`, `aac`, `opus`) | [export.md](export.md) |
| `rip` | the profile takes rip uploads from the ripper app (Full, with own-file storage) | [file-storage.md](../architecture/file-storage.md) |
| `media_servers` | at least one enabled media server has complete credentials | [media-servers.md](media-servers.md) |
| `sleep_timer` | the server-side sleep timer `POST /api/devices/{id}/sleep` (all profiles; needs a real queue) | [scenes.md](scenes.md) |
| `nodes` | nodes (clients with `kind: node`) can register and take jobs (all profiles) | [node-agent.md](../architecture/node-agent.md) |
| `line_in` | an approved node has the `line-in` role switched on, so `GET /api/line-ins` lists something | [line-in.md](../architecture/line-in.md) |
| `sub_devices` | `host` and `sub_devices` in the device detail, the admin link routes (all profiles) | [sub-devices.md](sub-devices.md) |
| `push` | the server holds a VAPID key pair, so `POST /api/push/subscribe` can deliver a Web Push for warning and critical alerts (a native app does not use it) | [pwa.md](../frontend/pwa.md) |
| `ha_discovery` | Home Assistant MQTT discovery is switched on | [home-assistant.md](../architecture/home-assistant.md) |

Not built yet, so never present: `podcasts` (workstream 8.2). It will appear as `podcasts: true` when it ships; a client that already checks for it needs no change. To add a key, add it to `ServerFeatures`, to this table, to the `ServerInfo` schema of `openapi.yaml` and to `tests/Feature/Api/ServerFeaturesTest.php`.

### Why the MQTT host isn't `MQTT_HOST`

In Docker, `MQTT_HOST` is the compose service name (`mosquitto`), which only resolves inside the compose network. The broker port is published on the same machine as the HTTP server (`compose.yaml` / `docker-compose.prod.yml` map `1883:1883`), so the request host is the right default. Set `MQTT_PUBLIC_HOST` (ansible: `mqtt_public_host`) only if the broker runs on another machine or behind a different name.

### Artwork URLs

Artwork URLs in `now_playing.artwork` and the MQTT `/data` payload are generated by Laravel's public disk, so they are prefixed with `APP_URL` (e.g. `http://remoment.local/storage/artwork/{hash}/320.jpg`), or root-relative (`/storage/artwork/…`) when `APP_URL` is empty. A client should not trust the scheme/host part: take the path (from `/storage/` on) and prefix it with `artwork_base_url`.

Keys: `kind` (`album` | `radio` | `source`, see CLAUDE.md), `proxy_512`, `proxy_320`, `proxy_120` (square covers), `proxy_bg` (1024×600 blurred, darkened background for the 7" landscape client), `proxy_bg_320x480` (the same, cropped to 320×480 for the 3.5" portrait client), `proxy_bg_480x480` (cropped to a 480×480 square screen), plus `colors` / `safe_colors`. Background sizes beyond the original are always named `proxy_bg_{width}x{height}`: a client picks `proxy_bg_{width}x{height}` for its own screen (e.g. `proxy_bg_320x480` on the 3.5" portrait client) and falls back to `proxy_bg` (1024×600) when that key is absent (e.g. an entry cached before the size existed, until it is regenerated). All are baseline JPEGs. The path segment after `/storage/artwork/` is `md5(original image URL)` and is stable, so clients can use it as a cache key (e.g. on SD). Generated source/radio logos use a fixed pseudo URL, so their hash is the same on every server.

---

## Firmware use

```
boot → find server (mDNS _remoment._tcp, or stored IP)
     → GET {server}/api/info
     → check api_version
     → store base_url, mqtt.host, mqtt.port
     → POST {api_base_url}/clients/register  (see client-devices.md)
     → connect MQTT to mqtt.host:mqtt.port
```

Re-fetch on reconnect: the response is cheap and the server's address may have changed (DHCP).
