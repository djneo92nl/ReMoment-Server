# Scenes

A scene is a household-wide list of steps run in order: "Evening" sets the volume, joins the kitchen to the living room and plays a playlist. Scenes have no owner (there are no accounts); anyone can list, run, create, change and delete them, like playlists and multiroom presets. Available in every profile (`features.scenes`), including the MQTT trigger subscriber and the device timers. Design: [action-layer.md](../architecture/action-layer.md), "What a scene step is".

```
GET    /api/scenes                  → { data: [SceneItem] }                 by name
POST   /api/scenes                  → 201 { data: Scene }
GET    /api/scenes/{id}             → { data: Scene }
PUT    /api/scenes/{id}             → { data: Scene }                       only the fields given
DELETE /api/scenes/{id}             → { status: "ok" }
POST   /api/scenes/{id}/activate    → 200 { status, results, data } | 202 { status: "queued", data }
```

**SceneItem** (list; what an app shortcut or widget needs): `{ id, name, icon, color, step_count, stop_on_error, schedule: { enabled, time, days }, triggers, trigger_debounce, alarm, last_run: { at, status, source } }`. `icon` is a free word (max 32, the client maps it), `color` is `#rrggbb` or `null`. **Scene** (detail) adds `steps` and `last_run.results`.

## Steps

```json
{ "operation": "setVolume", "device_id": 3, "params": { "volume": 30 } }
{ "type": "delay", "seconds": 5 }
```

- `operation` is an `operationId` of [openapi.yaml](openapi.yaml): every guest-level operation that changes something (not GET, no `security`), minus the exclude list in `config/scene-operations.php` (sign-in, registration, heartbeats, and the scene operations themselves, so a scene cannot run scenes). `App\Domain\Scenes\SceneOperations` lists them with their inputs.
- `device_id` fills the operation's device input (the `{device}` path parameter, or the body property named by `device_param`); `params` holds the other inputs (body fields, other path parameters). An operation not about one device takes no `device_id`.
- A delay is 1 to 60 seconds; the delays of one scene add up to at most 300 s. A scene has 1 to 50 steps.
- **Saving** validates every step against the operation's request schema (required, types, enums, bounds, unknown names). Errors are the usual `422 { message, errors }` with keys like `steps.2.params.volume`.

## Running

`App\Domain\Scenes\SceneRunner` validates every step again (an operation may have been removed since the scene was saved: then nothing runs, status `failed`), then calls each operation through `ApiBridge`, the in-process REST call, so a step answers exactly as a client call would: capability check (`422 unsupported`), `503 unreachable`, Spotify routing, route validation.

`results` has one entry per step: `{ index, type: operation|delay, operation, device_id, ok, status, error }` (`status` is the HTTP status; `error` is `code: message`). With `stop_on_error` a failing step ends the run and the steps after it are listed as `{ ok: false, skipped: true }`; without it the run continues. `status` is `ok` (all steps ok), `partial` (some failed) or `failed` (none ok, or the scene was invalid).

- A scene **without** delay steps runs inside the request: `200` with `results`.
- A scene **with** delays is queued (`RunScene` job, default queue) and answers `202 { status: "queued" }` at once with `last_run.status` `running`; read `GET /api/scenes/{id}` for `last_run` afterwards.
- Every run stores `last_run` (`at`, `status`, `source`: `api`, `schedule` or `trigger`, and the per-step `results`) on the scene.

## Schedule

`schedule_enabled`, `schedule_time` (`HH:MM`, the server's time zone) and `schedule_days` (ISO weekdays 1 to 7, `null` or empty = every day). A scheduled task `scenes-run-due` runs every minute (profile feature `scenes`, so in every profile), queues each due scene once per minute (`source: schedule`). Enabling a schedule without a time is `422`.

## Triggers

A scene can also start by itself. `triggers` is a list (at most 10) stored on the scene, `trigger_debounce` (5 to 3600 seconds, default 10) the pause described below:

```json
{ "type": "mqtt",   "topic": "home/doorbell/#", "payload": "ring" }
{ "type": "device", "device_id": 3, "event": "state",  "value": "playing" }
{ "type": "device", "device_id": 3, "event": "source", "value": "HDMI 2" }
```

- **mqtt**: the scene starts when a message arrives on a topic that matches the filter (`+` is one level, `#` the rest, only last). `payload` is optional: when given, the whole message (trimmed) must equal it. The server's own `remoment/player/#` topics cannot be used (a scene's steps change them, so the scene could start itself), nor `remoment/ha/command/#` (the Home Assistant command path would start it a second time); a filter that could match one of them is refused, `#` included. Retained messages are ignored, so a restart does not start scenes. The subscriber is `php artisan scenes:listen`, the supervisor program `scene-triggers` (started where the profile has the `scenes` feature, which is every profile today); it follows changes to the triggers within about five seconds and reconnects with a growing wait when the broker is away.
- **device, event `state`**: the device's state changed to `value` (`playing`, `paused`, `standby` or `unreachable`; `DeviceStateChanged`, so only a real change counts).
- **device, event `source`**: the device's source changed (its name or its input differs from the last report). `value` is optional: with it, only a change to that source name or input (case-insensitive, `HDMI 2`) counts. The first report of a device is not a change.

Saving validates the shapes, that a device exists, and the debounce; errors are `422` with keys like `triggers.1.value` or `trigger_debounce`. `triggers: []` clears them.

**A triggered scene is always queued** (`last_run.source` is `trigger`), because the callers are the device listeners and the subscriber, which must not wait for it. Two guards keep triggers from running away:

- **Debounce**: after a trigger starts a scene, the scene's further triggers are ignored for `trigger_debounce` seconds (also across processes).
- **Loop protection**: while a scene is queued or running, and for 15 seconds after it ended (`scenes.triggers.loop_guard_seconds`), its triggers are ignored, so the events its own steps cause (a play step makes the device report `playing`) cannot start it again. This is per scene: scene A starting scene B starting scene A is only slowed down by the debounces, so do not build that.

The device listeners read one cached index of all triggers (forgotten whenever a scene is saved or deleted), so a device report costs one cache read when no scene uses that device.

## Alarm

`alarm` is `{ device_id, minutes, start_volume, target_volume }` (`minutes` 1 to 60, `target_volume` higher than `start_volume`); `alarm: null` clears it. It is a variant of a scheduled scene (and runs on every run of it, also by hand or by a trigger, so you can try it): when the scene runs, the start volume is set on the device (best effort: a device in standby may refuse it), the steps run (switch on, play a playlist or station: built from the usual operations), and then the volume rises from `start_volume` to `target_volume` over `minutes` in steps about 30 seconds apart, by a device timer (below). `last_run.results` gets one extra entry `{ type: "alarm", device_id, minutes, ok, ... }`; the ramp is not started when the steps failed (all of them, or any with `stop_on_error`). The ramp ends early when someone changes the volume by hand (the device volume differs by more than 5 from what the ramp set last: timer status `taken_over`) or the device goes to standby (`stopped`). Volume limits and quiet hours of the device still cap each step.

## Sleep timer

For a device that has no `sleep_timer` capability of its own, or when you want a fade: a **server-side** timer, distinct from the native `/devices/{id}/sleep-timer`.

```
POST   /api/devices/{id}/sleep   { minutes, fade_seconds?, action? }   → 201 { data: SleepTimer }
GET    /api/devices/{id}/sleep                                         → { data: SleepTimer | null }
DELETE /api/devices/{id}/sleep                                         → { status: "ok", cancelled: bool }
```

`minutes` 1 to 480; `fade_seconds` 0 to 600 (default 60, at most the timer itself); `action` `stop` (default) or `standby` (needs the `power` capability). The device needs `media_controls`; anything else is `422 unsupported`. After `minutes` the volume has faded down over the last `fade_seconds` (in steps 10 seconds apart, at most 12), then the device is **stopped**, the **volume is put back** to what it was when the timer started (so the next track is not silent; in that order, so the restored level is not heard), then, for `standby`, the device is switched off. A device whose volume cannot be read just stops, without a fade. Starting another timer replaces the running one (a faded volume is put back first); `DELETE` cancels and puts a faded volume back. Not an error when none runs.

`SleepTimer`: `{ id, device_id, kind: "sleep", status: active|done|cancelled, started_at, ends_at, remaining_seconds, action, fade_seconds, original_volume }`.

`startSleep` and `cancelSleep` are scene operations like any other (a "Bedtime" scene can start one). MCP `scenes` actions `sleep`, `sleep_status`, `sleep_cancel`.

**How timers run.** Sleep timers and the alarm's ramp are rows of `device_timers` (`App\Domain\Scenes\DeviceTimers`): a list of timed events in `state`, one delayed `AdvanceDeviceTimer` job per event that does what is due and queues the next. Everything goes through `ApiBridge` (volume, stop, power), so no device contract was added and each step gets the capability checks and volume limits of a client call. A late job applies only the newest due volume and still finishes; a job for a cancelled or removed timer does nothing, so cancelling needs no queue access. It needs a real queue driver: the `sync` driver cannot delay a job, so a timer would never advance there. If the user changes the volume during a sleep fade the fade overrides it (the ramp, not the fade, yields to a hand on the knob). What a timer did is kept as a short log in its row (last 20 entries) for finding out why it did not.

## Not built (yet)

- Triggers by an NFC tag (tags have their own play endpoint), and a trigger on any other event than MQTT, state and source.
- Per-step conditions, parameters passed at activation, results over MQTT (a scene-activated event for Home Assistant exists, see [home-assistant.md](../architecture/home-assistant.md)).
- Listing the active timers of all devices (only `GET /devices/{id}/sleep`), cancelling an alarm's ramp other than by taking over the volume, a sleep timer for a sync group or multiroom as a whole (each device has its own).
- Multiroom presets are a web-only feature and not a step operation.
- Web UI extras: duplicate a scene, a run history.
- **Unverified on hardware:** the fade, the stop and the volume restore on real speakers (a speaker may keep playing its buffer for a moment after the stop, or ignore a volume set while it stops), the alarm ramp's start volume on a device in standby, and the MQTT subscriber against a real broker (tested with a recorder, and by running the command with no broker).

## Web

`/scenes` lists the scenes (icon, colour, step count, schedule time, last status) with Run, Edit and Delete; `/scenes/create` and `/scenes/{id}/edit` are the editor (`Livewire\SceneList`, `Livewire\SceneEditor`). Guest-accessible like `/multiroom`. The editor builds each step's fields from the operation's request schema (`SceneOperations::all()`: enums and booleans as selects, other values as text cast to the input's type on save, lists and objects as JSON; the inputs `playlist_id`/`playlist` and `station` are pickers by name, playlists only where the profile has the library, and `album_id`, `artist_id` and `track_id` are a search box plus a short result list (20 matches by name, albums and tracks also by artist; Full library profiles only); a deleted record stays visible as "Missing (#id)"), moves and removes steps, adds waits, and saves through the same `SceneRunner::validate` as the API, showing a step's errors under it. A Run on a scene with waits is queued, as in the API. Icons and colours are a fixed set (the API accepts any word and any `#rrggbb`; an unknown value is kept and shown as "Custom").

**Add to scene.** The album, artist and playlist pages have an "Add to scene" button (`Livewire\AddToScene`, next to Export): choose a scene or "New scene…", a device that can play library items, and optionally Shuffle. It appends a `playAlbum`, `playArtist` or `playPlaylist` step, checked by the same `SceneRunner::validate` (a scene of 50 steps refuses it), and links to the editor.

**Triggers and alarm in the editor.** Below the schedule: a Triggers section (add an MQTT trigger with a topic filter and optional payload, or a device trigger: a device, "its state becomes" with a state list, or "its source changes" with an optional source name; the pause after a start) and an Alarm card (device, minutes, from and to volume). Errors show under the trigger or the card; saving goes through `SceneTriggers::validate` and `SceneAlarm::validate`, the API's own code. `/scenes` shows a bolt and the number of triggers, and a bell for an alarm, next to each scene, and under the scenes a **Sleep timer** card (`Livewire\SleepTimerCard`: device, minutes, fade, stop or standby, Start; running timers with their remaining minutes and Cancel). The card calls the REST endpoints through `ApiBridge`, like MCP.

MCP: tool `scenes` (list, get, activate, create, update, delete, sleep, sleep_status, sleep_cancel; `triggers`, `trigger_debounce` and `alarm` on create and update), see [mcp.md](mcp.md).
