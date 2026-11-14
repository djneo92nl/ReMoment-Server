# Scenes

A scene is a household-wide list of steps run in order: "Evening" sets the volume, joins the kitchen to the living room and plays a playlist. Scenes have no owner (there are no accounts); anyone can list, run, create, change and delete them, like playlists and multiroom presets. Available in every profile (`features.scenes`). Design: [action-layer.md](../architecture/action-layer.md), "What a scene step is".

```
GET    /api/scenes                  → { data: [SceneItem] }                 by name
POST   /api/scenes                  → 201 { data: Scene }
GET    /api/scenes/{id}             → { data: Scene }
PUT    /api/scenes/{id}             → { data: Scene }                       only the fields given
DELETE /api/scenes/{id}             → { status: "ok" }
POST   /api/scenes/{id}/activate    → 200 { status, results, data } | 202 { status: "queued", data }
```

**SceneItem** (list; what an app shortcut or widget needs): `{ id, name, icon, color, step_count, stop_on_error, schedule: { enabled, time, days }, last_run: { at, status, source } }`. `icon` is a free word (max 32, the client maps it), `color` is `#rrggbb` or `null`. **Scene** (detail) adds `steps` and `last_run.results`.

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
- Every run stores `last_run` (`at`, `status`, `source`: `api` or `schedule`, and the per-step `results`) on the scene.

## Schedule

`schedule_enabled`, `schedule_time` (`HH:MM`, the server's time zone) and `schedule_days` (ISO weekdays 1 to 7, `null` or empty = every day). A scheduled task `scenes-run-due` runs every minute (profile feature `scenes`, so in every profile), queues each due scene once per minute (`source: schedule`). Enabling a schedule without a time is `422`.

## Not built (yet)

- Triggers by MQTT, by device events (TV on, source changed) or by an NFC tag; only the clock triggers a scene, plus a call.
- Alarm clock (a scene that ramps volume) and sleep timer.
- Per-step conditions, parameters passed at activation, results over MQTT (a scene-activated event for Home Assistant).
- Multiroom presets are a web-only feature and not a step operation.
- Web UI extras: duplicate a scene, a run history, pickers for albums, artists and tracks (playlists and stations have one).

## Web

`/scenes` lists the scenes (icon, colour, step count, schedule time, last status) with Run, Edit and Delete; `/scenes/create` and `/scenes/{id}/edit` are the editor (`Livewire\SceneList`, `Livewire\SceneEditor`). Guest-accessible like `/multiroom`. The editor builds each step's fields from the operation's request schema (`SceneOperations::all()`: enums and booleans as selects, other values as text cast to the input's type on save, lists and objects as JSON; the inputs `playlist_id`/`playlist` and `station` are pickers by name, playlists only where the profile has the library, and a deleted record stays visible as "Missing (#id)"), moves and removes steps, adds waits, and saves through the same `SceneRunner::validate` as the API, showing a step's errors under it. A Run on a scene with waits is queued, as in the API. Icons and colours are a fixed set (the API accepts any word and any `#rrggbb`; an unknown value is kept and shown as "Custom").

MCP: tool `scenes` (list, get, activate, create, update, delete), see [mcp.md](mcp.md).
