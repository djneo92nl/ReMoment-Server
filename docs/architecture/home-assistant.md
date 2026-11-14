# Home Assistant MQTT Discovery (plan)

> **Status:** the MQTT discovery part is built (2026-11-14, see [What is built](#what-is-built-2026-11-14)); the `ha:package` REST commands were built earlier. Plan written 2026-10-13. **In scope for 1.0** (decided 2026-10-26), see workstream 8a in [v1-roadmap.md](v1-roadmap.md); the sections "Automations" below were added then.

Expose ReMoment's own data to Home Assistant (HA) through MQTT discovery: library and play stats, client devices, sources and server health, and give HA what it needs to build automations on them.

**Deliberately not exposed: players.** HA already has every speaker through its own integrations (ASE, DLNA, Chromecast, Sonos), so a ReMoment `media_player` would show the same speaker a second, third or fourth time. No `media_player`, no transport, volume, seek or queue entities. The `remoment/player/{id}/…` topics stay as they are; HA automations can still use them as plain MQTT triggers.

## What is built (2026-11-14)

Code in `App\Domain\HomeAssistant\` (`HomeAssistant` settings, `EntityCatalog`, `DiscoveryPublisher`, `StatsSnapshot`, `Hooks`) and `Listeners\Device\PublishHomeAssistantEvents`; tests in `tests/Feature/HomeAssistant/`.

- **Opt-in.** Setting `ha_discovery_enabled` (falls back to `HA_DISCOVERY_ENABLED`, default off) and `ha_discovery_prefix` (`HA_DISCOVERY_PREFIX`, default `homeassistant`), both in `config/mqtt.php`. A switch, the prefix and "Publish now" are on `/settings/mqtt`; switching off (or changing the prefix) clears the retained configs under the old prefix.
- **Command** `php artisan ha:discovery [--remove]`: publishes every config and state (retained, no dedupe), or clears every config this server ever published (list kept in Setting `ha_discovery_topics`, so a deleted client is reached too) and sets `remoment/ha/status` to `offline`. Works whether or not the setting is on.
- **Republish.** The scheduler task `ha-discovery` (every five minutes, only while discovery is on; gate `home_assistant`, all profiles) publishes the states each time and the configs through `publishIfChanged` with `mqtt_published_ha_*` keys, whose one-hour TTL makes them go out hourly. The server never subscribes, so HA's `homeassistant/status` birth message is not used: a restarted HA reads the retained configs from the broker, and a broker without persistence gets them back within the hour (run `ha:discovery` or "Publish now" to be quicker). It is not run on boot.
- **Entities** (all with `unique_id` `remoment_*`, one `availability` entry on `remoment/ha/status`, `expire_after` 900 s on sensors):
  - Hub device **ReMoment Server**: sensors `plays_today`, `plays_7d`, `plays_total`, `devices_playing` (state `remoment/ha/stats`); with the `library` profile also `library_artists/albums/tracks`, `favorites`, `top_artist_30d`, `top_album_30d`, `top_track_30d` (top 10 in the `top` attribute); `binary_sensor` `problem` for scheduler (alert `scheduler.silent`), queue (`queue.stuck`) and external services (any cached probe in `error`, names in `failing`) from `remoment/ha/health`; `enrichment_backlog` (profile `enrichment`) and `analysis_backlog` (profile `analysis`) diagnostics; `active_alerts` reading the existing retained `remoment/alerts/active`; `pending_clients` from `remoment/ha/hub`. No per-alert binary sensors: alerts are an event entity instead.
  - Each approved client: a child device (`via_device` = the server) with `online` (`last_seen_at` within 10 minutes), `battery`, `charging`, and diagnostic `firmware`, `build`, `ip` (state `remoment/ha/client/{id}`, published on every client save, deduplicated).
  - One sensor per device, "{name} source": state is the source in the cached now playing (never `in_use`), attributes the visible sources, `input` and `device_id` (`remoment/ha/sources/{deviceId}`). Attached to the server device, not the speaker's own HA device (no MAC lookup; open question 3 stays open).
  - `event` entities, non-retained payloads `{"event_type": …, …}` on `remoment/ha/events/{entity}`: `alert` (`raised`/`resolved`, bridged from `Alerts::publish`), `scene_activated` (`activated`, with `status` and `source`), `client_pending`, `track_started`, `state_changed` (event type is the new state, with `previous`), `source_changed` (with `source` and `input` for a TV). Playback events carry `device_id` and `device`. They are only published while discovery is on, and `track_started`/`source_changed` are deduplicated per device (`ha_track_*`, `ha_source_*`; the first source seen is not an event).
- **Differences from the plan:** `StatsSnapshot` was written new instead of moving the queries out of `StatsController` (the stats page is untouched); one aggregate `external_services` sensor instead of one per service; no `ha:publish-discovery` name (it is `ha:discovery`); the settings page has no download of the `ha:package` YAML yet.
- **Never:** `media_player` entities, command topics, or a subscriber.
- **Unverified in a real Home Assistant:** the whole thing was tested with a fake publisher only. Not yet seen: that HA accepts the `object_id` + `unique_id` pair and the `device` block, that the `none` rendered for a missing battery or top item shows as unknown, that `event` entities fire from the payload shape, that `expire_after` and the availability topic together behave as intended, and the HA minimum version (`event` entities need 2023.8). The broker must be the one HA uses (open question 1).

## What is exposed

| Group | Entities |
|---|---|
| Stats | `plays_today`, `plays_7d`, `plays_total`, `top_artist_30d`, `top_album_30d`, `top_track_30d` (top 10 as attributes), `library_artists`, `library_albums`, `library_tracks`, `favorites`, `devices_playing` |
| Server health | `binary_sensor` (`problem`) for scheduler, queue and each external service; sensors for enrichment and analysis backlog |
| Clients | per client: `binary_sensor` `connectivity` (online = `last_seen_at` recent), `sensor` `battery`, `binary_sensor` `battery_charging`, diagnostic sensors for firmware, build and IP. Hub-level `pending_clients` count so HA can notify when a new ESP32 needs approval |
| Sources | one sensor per physical device ("Living Room source"): state = active source name, attributes = visible sources. Follows `docs/ux/sources.md`: never treat `in_use` as selected |

## Device layout in HA

- One HA device, **ReMoment Server**, carries stats, health and the source sensors. No device appears per speaker.
- Each client is a child device (`via_device` = the server) named after the client.
- Open question: if a speaker's MAC is stored, the source sensors could use `connections: [["mac", …]]` so HA attaches them to the speaker's existing device instead of the server device. Check what `device_meta` holds before deciding.

## Topics

- Discovery: `homeassistant/<component>/remoment_<id>/config`, retained (prefix configurable).
- State: retained JSON under `remoment/ha/`: `stats`, `health`, `client/{id}`, `sources/{deviceId}`. Entities read their field with `value_template`.
- Availability: sensors use `expire_after`. A last-will message does not fit, because `MqttService` publishes from many short-lived processes.
- Removal: publish an empty retained config to delete the entity (client deleted, device removed, feature switched off).

## Code

- New namespace `App\Domain\HomeAssistant\`:
  - `EntityCatalog`: entity definitions
  - `DiscoveryPublisher`: configs and removal
  - `StatsSnapshot`: aggregates
- Move the aggregate queries out of `StatsController` into `StatsSnapshot`; the stats page and HA share them.
- Command `ha:publish-discovery [--clear]`: republishes discovery and state. Scheduled daily, also run on boot.
- Stats refresh every 5 minutes via the scheduler and are cached.
- Client state publishes on heartbeat, approve, rename and delete, using `MqttService::publishIfChanged`.
- Source sensors publish when `DeviceSource` or the active source changes.
- Settings: `HA_DISCOVERY_ENABLED` (default off), discovery prefix (default `homeassistant`), a "Publish now" button on the settings page. Keys live in `config/mqtt.php`.
- No REST or OpenAPI change, so no client change.

## Automations

HA automations need things to trigger on and things to call.

**Triggers** (all publish-only, no change to how the server works):
- The existing topics are usable as MQTT triggers as they are: `remoment/player/{id}/state`, `/data` (empty = nothing playing), `/modes`, `/volume`, `/multiroom`.
- Added through discovery as HA `event` entities (or device triggers), each fired by a non-retained message under `remoment/ha/events/`: `track_started`, `state_changed`, `source_changed` (carries the `input` for a TV, e.g. "HDMI 2", and `signal`), `client_pending` (a new ESP32 waits for approval), `scene_activated`, and `alert` (workstream 8.6 publishes `remoment/alerts/{kind}`; they are bridged here). A sub-device link becoming active (workstream 2a) is a `source_changed` event of the host.
- Payloads carry the device id and name, so one automation can filter on a room.

**Actions** (HA calls ReMoment): through the REST API the server already has, not MQTT, because the server only publishes and a command subscriber would be a new long-running process.
- A generated `remoment.yaml` HA package (download on the settings page; the server's address filled in) with `rest_command` entries: activate a scene, play a playlist/album/radio station on a device, move playback, start/stop a sync group, press a TV remote key, set volume, standby.
- The doc keeps worked examples: "scene when the TV input becomes HDMI 2", "pause the music when the doorbell rings", "morning scene on a schedule".
- No auth to configure: the API is open on the LAN, as for every client. Admin-only calls are not in the package.

### Generated rest_command package

`php artisan ha:package [--output=remoment.yaml] [--base-url=http://remoment.local]` writes one YAML file with a `rest_command` per scene-capable operation (every guest-level, non-GET operation of the contract minus `scene-operations.exclude`; `config/scene-operations.php`), generated from `docs/api/openapi.yaml` (`App\Domain\Scenes\HaPackage`). Each command is named `remoment_{snake_case operationId}` (`remoment_move_playback`), its `url` is the server's `APP_URL` (or `--base-url`) plus `/api` and the operation's path with `{device}` as `{{ device }}`, its `method` the operation's, and its `payload` a Jinja template that renders the JSON body from the service data: required properties first, optional ones only when the call passes them. The device is just the operation's `device_param` input (`device` for most). Only guest-level operations are in the package (no admin token to configure). A settings-page download and scene activation are not built yet (scenes are not).

```yaml
rest_command:
  remoment_move_playback:
    url: 'http://remoment.local/api/devices/{{ device }}/move'
    method: post
    content_type: application/json
    payload: "{% set p = {'to_device_id': to_device_id} %}{% if stop_source is defined %}{% set p = dict(p, stop_source=stop_source) %}{% endif %}{{ p | tojson }}"
```

**Unverified:** the file has only been parsed (Symfony YAML in `tests/Unit/Scenes/HaPackageTest.php`), never loaded by a running Home Assistant; the template syntax follows HA's `rest_command` documentation. Query parameters (none of the listed operations has one) appear only when required. See [action-layer.md](action-layer.md) section 8.

**Later, optional:** an MQTT command path (a `button` per scene, a source `select`) means the server subscribes to `remoment/ha/+/set`. That is a new `mqtt:listen` process next to `device:listen`; decide when the REST package proves too clumsy. Open question 4 below.

**Still not exposed:** players as `media_player` entities (HA has its own integrations for every speaker). A TV is the same: its power, input and now playing reach HA as the source sensor and events above, not as a second TV entity.

## Phases (1 to 6 built; 7 is `ha:package`)

1. Plumbing: setting, `DiscoveryPublisher`, hub device with a few health entities. Verify in HA.
2. Stats sensors and the `StatsSnapshot` refactor.
3. Client devices and the pending-approval sensor.
4. Per-device source sensors.
5. Removal and rename handling, docs, tests.
6. Event entities and the bridged alerts (needs scenes and alerts from workstream 8).
7. The generated REST package and the worked examples.

## Tests

A faked `MqttService` asserts payloads, retain flags, deduplication and removal. `StatsSnapshot` is tested on SQLite and the MySQL path, as `StatsController` is today.

## Docs to update when built

- `server/CLAUDE.md`: documentation table and a short note.
- `docs/architecture/live-updates.md`: link to the new `remoment/ha/` topics.

## Open questions

1. Does HA use the same Mosquitto broker as ReMoment? If not, a Mosquitto bridge is needed first.
2. Which stats windows matter (today, 7d, 30d, all time)?
3. Is a MAC stored for ASE, Sonos and DLNA devices (see Device layout)?
4. Should source switching, and scene start, ever be offered as an opt-in `select`/`button` over MQTT? The REST package covers it for now; an MQTT command path means a subscriber process (see Automations).
