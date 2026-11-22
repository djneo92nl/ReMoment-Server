# MQTT reference

The Mosquitto broker runs in Docker on port 1883. Each device's MQTT base topic is `remoment/player/{device_id}` and is also returned in the API as `mqtt_topic`.

### Topics

| Topic | Trigger | Payload |
|-------|---------|---------|
| `remoment/player/{id}/data` | New track starts (retained); cleared on standby/unreachable | `{ "track": "Name", "artist": "Name", "album": "Name"|null, "duration": 213|null, "artwork": { "kind": "album", "proxy_512": "…", "proxy_320": "…", "proxy_120": "…", "proxy_bg": "…", "proxy_bg_320x480": "…", "proxy_bg_480x480": "…", "colors": ["#…"], "safe_colors": ["#…"] } }` (same `artwork` object as the REST API; ~800 bytes, so MQTT clients need a buffer above PubSubClient's 256-byte default). An **empty (zero-length) payload** means nothing is playing |
| `remoment/player/{id}/progress` | Every second while playing | Progress as a percentage of the track, 0–100 (integer string) |
| `remoment/player/{id}/state` | State transition (retained) | `{ "state": "playing" }` — `playing` / `paused` / `standby` / `unreachable` |
| `remoment/player/{id}/volume` | Volume or mute changes (retained) | `{ "volume": 45, "muted": false }` |
| `remoment/player/{id}/battery` | Battery level or charging changes (retained) | `{ "level": 87, "charging": false }` — only for devices that have a battery; same object as the REST `battery` |
| `remoment/player/{id}/multiroom` | Multiroom role changes (retained) | `{ "role": "host", "host": null, "listeners": [{ "id": 4, "name": "Beoplayer M5" }] }` — `role` is `standalone` / `host` (others listen to it) / `listener` (`host` names the device it is joined to); same object as the REST `multiroom`, `null` there while unknown |
| `remoment/player/{id}/modes` | Shuffle/repeat/liked changes (retained) | `{ "shuffle": true, "repeat": "off", "liked": null }` — as `now_playing.modes`; `null` = unknown/unsupported. While Spotify is routed to a speaker, Spotify's modes are published on the speaker's id; afterwards its own (or all `null`) |
| `remoment/ha/*` (opt-in Home Assistant discovery; configs under `homeassistant/<component>/remoment_*/config`) | `ha:discovery`, the 5-minute scheduler task, client saves | Retained JSON states `stats`, `health`, `hub`, `client/{id}`, `sources/{deviceId}`, retained `status` (`online`/`offline`); non-retained events under `events/` (`alert`, `scene_activated`, `client_pending`, `track_started`, `state_changed`, `source_changed`, `command_result`). **Subscribed** (opt-in, `ha:listen`): `remoment/ha/command/scene/{id}` and `remoment/ha/command/operation` (scene allow-list only), retained messages ignored. See `docs/architecture/home-assistant.md` |
| `remoment/player/{id}/queue` | Queue edited through the server (add, play next, remove, move, clear), **not** retained | `1` — a ping: re-read `GET /api/devices/{id}/queue` |

`/data` is retained so a client subscribing later (e.g. after switching device) gets the current track at once; when the device goes to standby or becomes unreachable the server publishes an empty retained payload, which also removes the retained track from the broker, so clients must treat an empty `/data` as "nothing playing". It is republished from the cache when a device comes back, and identical payloads aren't repeated. `artwork` is absent in the MQTT payload if a real image is not yet processed; `kind` is `album` | `radio` | `source` as in the REST API. Published by the `PublishNowPlayingToMqtt`, `PublishProgressToMqtt`, `PublishStateToMqtt`, `PublishVolumeToMqtt`, `PublishBatteryToMqtt` and `PublishModesToMqtt` listeners. The server only publishes and never subscribes. Browsers subscribe over the WebSocket listener on port 9001 to drive live UI refreshes; see `docs/architecture/live-updates.md`.

---
