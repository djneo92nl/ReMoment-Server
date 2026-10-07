# Live Updates (MQTT push to the browser)

The UI stays server-rendered Livewire. The browser subscribes to the Mosquitto broker over WebSockets only to learn **when** to refresh, instead of polling every second.

```
DeviceListener ──events──▶ UpdateDeviceCache ──▶ DeviceCache::updateState()
                                                    │ state changed?
                                                    ▼
                                          DeviceStateChanged ──▶ PublishStateToMqtt
NowPlayingUpdated ──▶ PublishNowPlayingToMqtt ─┐
ProgressUpdated   ──▶ PublishProgressToMqtt  ──┼──▶ Mosquitto :1883 ──(ws :9001)──▶ public/js/remoment-live.js
VolumeUpdated     ──▶ PublishVolumeToMqtt    ──┤
PlaybackModes…    ──▶ PublishModesToMqtt     ──┘                                       │
                                                           Livewire: $wire.$refresh() ◀┤
                                                           /receiver: pollNow()       ◀┘
```

## Topics

All under `remoment/player/{device_id}/`:

| Topic | Retained | Published when | Payload |
|---|---|---|---|
| `state` | yes | `DeviceCache::updateState()` sees a transition (not on every heartbeat write) | `{"state":"playing"}` — `playing`/`paused`/`standby`/`unreachable` |
| `volume` | yes | `VolumeUpdated` with a level or mute state different from the last one published | `{"volume":45,"muted":false}` — a listener that doesn't report mute keeps the last known value (`false` if never known) |
| `modes` | yes | `PlaybackModesUpdated` with values different from the last ones published (listeners report on every poll; the API after a change) | `{"shuffle":true,"repeat":"off","liked":null}` — `repeat` is `off`/`all`/`one`; `null` = unknown or unsupported. Same object as `now_playing.modes`. While Spotify is routed to a speaker, Spotify's modes go to the speaker's topic (its own listener's reports are held back); when routing ends the speaker's own values, or all `null`, are republished |
| `multiroom` | yes | `MultiRoomUpdated` with a role different from the last one published (the ASE listener reads `ActiveSources` on connect and every 5 s) | `{"role":"host","host":null,"listeners":[{"id":4,"name":"…"}]}` — `role` `standalone`/`host`/`listener`; `host` is the device a listener is joined to. Idle: `primaryJid` empty; host: `primaryJid` is its own and others are in `listenerList`; listener: `primaryJid` is the host's. Mozart/Sonos don't report it yet |
| `data` | yes | `NowPlayingUpdated` (new track), unless identical to the last payload; republished from the cache on a transition from standby/unreachable to playing/paused; an **empty payload** on a transition to standby/unreachable | `{"track","artist","artwork"?}` — `artwork` is `kind` (`album`/`radio`/`source`) plus the `ArtworkCache` entry: `proxy_512`, `proxy_320`, `proxy_120`, `proxy_bg` (1024×600 background), `proxy_bg_320x480` (320×480 portrait background; `proxy_bg_480x480` is the square one; clients pick `proxy_bg_{w}x{h}` for their screen and fall back to `proxy_bg`) URLs plus `colors`/`safe_colors`; see CLAUDE.md "Artwork Caching". Without any image (line-in, TV, a station without a logo) it is a generated source/radio logo with the same keys, so it is absent only while a real image is first processed |
| `progress` | no | `ProgressUpdated`, about once a second while playing | Percentage `0`–`100` as a plain string |

The `data` payload is around 600 bytes with artwork. PubSubClient (ESP32/ESP8266) drops messages above its 256-byte default buffer, so firmware must call `setBufferSize()` (1024 or more).

`state`, `volume`, `modes` and `data` are retained so a firmware client that connects later (or subscribes to another device) gets the current value immediately.

**Empty `/data`:** when a device goes to standby or becomes unreachable, `SyncNowPlayingDataWithState` publishes a zero-length retained payload on `/data`. That removes the retained track from the broker, and live subscribers receive it as an empty message: clients must treat an empty `/data` as "nothing playing" (don't JSON-parse it). `remoment-live.js` passes it on as `data: ''`; `liveDevice` and `/receiver` just re-fetch, so they need no special case. When a device comes back (e.g. a listener recovering from an error, which doesn't re-announce the track it already reported), the cached now-playing is republished. `UpdateDeviceCache` stores the new now-playing before updating the state, so that republish already carries the new track and `PublishNowPlayingToMqtt` skips its identical payload.

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
| `liveDevice(deviceId, fallbackMs = 1000, safetyMs = 30000)` | Root element of `DeviceCard`, `Nowplaying`, `DeviceQueue` (replaces `wire:poll.1s`) | Debounced `$wire.$refresh()` on non-retained `state`/`data`/`volume`/`modes` messages for that device. Polls every `fallbackMs` while disconnected and every `safetyMs` while connected. |
| `progressTicker(deviceId, position, duration, playing)` | `<x-progress-ticker>` | Advances the bar and elapsed time locally every second. Calls `$wire.$refresh()` when the pushed `progress` percentage differs from the local estimate by more than 3 (e.g. after a seek from another client). Click-to-seek calls `$wire.seek(seconds)` when `seekable`. |

`<x-progress-ticker>` is `wire:key`ed on position and play state, so each fresh server render re-seeds the ticker.

Retained messages are ignored for refreshes, because the page was already rendered with that state.

## Degradation

If mqtt.js can't load or the broker can't be reached (HTTPS page with a `ws://` broker, port 9001 not exposed, old TV browser), `RemomentLive.connected` stays `false`. Livewire components then keep refreshing every second and `/receiver` keeps polling every 3 seconds, which is the behavior before push existed.
