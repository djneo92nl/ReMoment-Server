# Live Updates (MQTT push to the browser)

The UI stays server-rendered Livewire. The browser subscribes to the Mosquitto broker over WebSockets only to learn **when** to refresh, instead of polling every second.

```
DeviceListener ──events──▶ UpdateDeviceCache ──▶ DeviceCache::updateState()
                                                    │ state changed?
                                                    ▼
                                          DeviceStateChanged ──▶ PublishStateToMqtt
NowPlayingUpdated ──▶ PublishNowPlayingToMqtt ─┐
ProgressUpdated   ──▶ PublishProgressToMqtt  ──┼──▶ Mosquitto :1883 ──(ws :9001)──▶ public/js/remoment-live.js
VolumeUpdated     ──▶ PublishVolumeToMqtt    ──┘                                       │
                                                           Livewire: $wire.$refresh() ◀┤
                                                           /receiver: pollNow()       ◀┘
```

## Topics

All under `remoment/player/{device_id}/`:

| Topic | Retained | Published when | Payload |
|---|---|---|---|
| `state` | yes | `DeviceCache::updateState()` sees a transition (not on every heartbeat write) | `{"state":"playing"}` — `playing`/`paused`/`standby`/`unreachable` |
| `volume` | yes | `VolumeUpdated` with a level different from the last one published | `{"volume":45}` |
| `data` | no | `NowPlayingUpdated` (new track) | `{"track","artist","artwork"?}` |
| `progress` | no | `ProgressUpdated`, about once a second while playing | Percentage `0`–`100` as a plain string |

`state` and `volume` are retained so a firmware client that connects later gets the current value immediately.

Caveat: `state` only fires on writes through `DeviceCache::updateState()`. When a listener dies, its `device:{id}:state` key expires silently (TTL 3600s) and no `unreachable` message is sent — the UI's safety refresh (below) and `/settings/health` cover that case.

## Server side

- `App\Services\MqttService` is a **singleton** holding one connection per process, reconnecting once on a failed publish. The client ID is `{MQTT_CLIENT_ID}-{pid}`, so each long-running listener gets its own session (the broker would otherwise disconnect clients sharing an ID).
- Configuration is in `config/mqtt.php`: `MQTT_HOST`, `MQTT_PORT`, `MQTT_CLIENT_ID`, `MQTT_WS_URL` (the browser's broker URL; default `ws(s)://{page host}:9001`), and `MQTT_PUBLIC_HOST` / `MQTT_PUBLIC_PORT` (the broker address advertised to firmware clients by `GET /api/info`; default request host / `MQTT_PORT`).
- Listeners are registered explicitly in `AppServiceProvider`. Event auto-discovery is disabled in `bootstrap/app.php` (`withEvents(discover: false)`) because it registered every listener a second time.

## Browser side

`resources/views/components/live-scripts.blade.php` (included by `layouts/app` and `receiver`) loads mqtt.js from jsDelivr plus `public/js/remoment-live.js`, both `defer`red so they run before the Vite bundle starts Livewire/Alpine.

`window.RemomentLive`:
- `connected` — whether the WebSocket is up.
- `on(fn)` — subscribe; `fn({deviceId, kind, data, retained})`, returns an unsubscribe function.

Two Alpine components are registered for Livewire views:

| Component | Where | Behavior |
|---|---|---|
| `liveDevice(deviceId, fallbackMs = 1000, safetyMs = 30000)` | Root element of `DeviceCard`, `Nowplaying`, `DeviceQueue` (replaces `wire:poll.1s`) | Debounced `$wire.$refresh()` on non-retained `state`/`data`/`volume` messages for that device. Polls every `fallbackMs` while disconnected and every `safetyMs` while connected. |
| `progressTicker(deviceId, position, duration, playing)` | `<x-progress-ticker>` | Advances the bar and elapsed time locally every second. Calls `$wire.$refresh()` when the pushed `progress` percentage differs from the local estimate by more than 3 (e.g. after a seek from another client). Click-to-seek calls `$wire.seek(seconds)` when `seekable`. |

`<x-progress-ticker>` is `wire:key`ed on position and play state, so each fresh server render re-seeds the ticker.

Retained messages are ignored for refreshes, because the page was already rendered with that state.

## Degradation

If mqtt.js can't load or the broker can't be reached (HTTPS page with a `ws://` broker, port 9001 not exposed, old TV browser), `RemomentLive.connected` stays `false`. Livewire components then keep refreshing every second and `/receiver` keeps polling every 3 seconds, which is the behavior before push existed.
