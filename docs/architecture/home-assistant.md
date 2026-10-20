# Home Assistant MQTT Discovery (plan)

> **Status:** plan, nothing built. Written 2026-10-13.

Expose ReMoment's own data to Home Assistant (HA) through MQTT discovery: library and play stats, client devices, sources and server health.

**Deliberately not exposed: players.** HA already has every speaker through its own integrations (ASE, DLNA, Chromecast, Sonos), so a ReMoment `media_player` would show the same speaker a second, third or fourth time. No `media_player`, no transport, volume, seek or queue entities. The `remoment/player/{id}/…` topics stay as they are; HA automations can still use them as plain MQTT triggers.

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

## Phases

1. Plumbing: setting, `DiscoveryPublisher`, hub device with a few health entities. Verify in HA.
2. Stats sensors and the `StatsSnapshot` refactor.
3. Client devices and the pending-approval sensor.
4. Per-device source sensors.
5. Removal and rename handling, docs, tests.

## Tests

A faked `MqttService` asserts payloads, retain flags, deduplication and removal. `StatsSnapshot` is tested on SQLite and the MySQL path, as `StatsController` is today.

## Docs to update when built

- `server/CLAUDE.md`: documentation table and a short note.
- `docs/architecture/live-updates.md`: link to the new `remoment/ha/` topics.

## Open questions

1. Does HA use the same Mosquitto broker as ReMoment? If not, a Mosquitto bridge is needed first.
2. Which stats windows matter (today, 7d, 30d, all time)?
3. Is a MAC stored for ASE, Sonos and DLNA devices (see Device layout)?
4. Should source switching ever be offered as an opt-in `select`? Left out: it is control, not sensing.
