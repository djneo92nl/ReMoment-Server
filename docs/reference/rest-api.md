# REST API reference (detailed)

Base URL: `/api` — no authentication required.

### Server Info

**`GET /api/info`** — bootstrap info for client devices: `{ name, version, api_version, base_url, api_base_url, artwork_base_url, mqtt: { host, port, ws_port, ws_url, topic_prefix }, placeholder }`. `placeholder` is a full artwork object (`kind: "source"`, the music-note logo, rendered on the spot if missing) for clients to show whenever there is no artwork, e.g. while a cover is first processed. `features` is `{ alerts: true, scenes: true, profile: "control"|"standard"|"full", deep_analysis: bool, deep_analysis_kinds?: ["sections", "stems"] }` (only keys true today; `deep_analysis` while a deep-analysis service answers its health check, `deep_analysis_kinds` lists the kinds that can run now, present only when not empty; `stems` and `stem_audio` only once the stems service's opt-in weights are downloaded). Addresses are as the client reached the server; `mqtt.host` is `MQTT_PUBLIC_HOST` or the request host (never the Docker-internal `MQTT_HOST`); `mqtt.ws_port`/`ws_url` are the WebSocket listener (`MQTT_PUBLIC_WS_PORT`, default 9001; `MQTT_WS_URL` overrides the URL). See `docs/api/server-info.md`.

### Listening insights

`GET /api/library/insights?days=&scope=&client=` (`App\Domain\Stats\ListeningInsights`, `docs/api/insights.md`): skip rate per artist (a finished play of a track with a known length that lasted under `SKIP_FRACTION` = half of it, the one skip rule), new against repeat tracks per Monday-week (first play ever vs later), mood/BPM/arousal per part of the day and what is played late (22:00-04:59), over the last `days` (default 90, max 365), hours in the app timezone, one aggregate query per block, cached five minutes. Household-wide. Standard and Full (Control `422 not_available_in_profile`); `parts_of_day` and `late.moods` are empty without analysis (`analysis: false` outside Full). The cards sit at the bottom of `/stats`; MCP `listening_insights`.

### Discover

`GET /api/library/discover?sections=&limit=&seed=&scope=&client=` (`App\Domain\Library\Discover` over `Rediscover`, `docs/api/discover.md`, `docs/frontend/discover.md`): the Discover shelves as data, a list of `{ key, title, description, kind: album|artist|track|catalogue|mode, items }` where every item has a `reason` in words, so a new shelf needs no client release. Rediscover shelves come from `plays` and the favorites only (one aggregate query each, selection cached 5 minutes, thresholds in `config/discover.php`): forgotten favorites, used to play (listening *days*, not plays), on this day (a window around today's date in earlier years), forgotten artists and songs, not played in a year. Also `new_releases` (catalogue, not for a client token), `mixes_well` (`MixesWith` from the analysed track played last; Full), `listening_modes` (Full) and `random_albums` (`seed`). Empty sections are left out; Standard and Full (Control `422 not_available_in_profile`); household-wide, no per-user state. Web: `/library/discover` (`Livewire\DiscoverHub`), a chip on `/library` and a Library menu entry; the hub has a marked `SLOT similar_tracks` in `Discover::sections()`; MCP `browse_library` kind `discover`.

### Similar tracks

`GET /api/library/tracks/{id}/similar?cursor=&scope=&client=&device_id=` (`App\Domain\Library\SimilarTracks`, `docs/api/similar-tracks.md`): the tracks of the scope whose Discogs-EffNet embedding (`file_embeddings`, the ML sidecar's `embedding`, own files only) is nearest by cosine similarity, `{ canonical_id, source: { model, dimensions }, data: [SearchTrack + { similarity, distance, reason: { moods, genres, tempo_diff_percent, same_artist } }], next_cursor }`, 50 per page, 200 deep, cached five minutes; never the track itself, its AcoustID recording or a same-artist same-title track; `422 not_analysed` without an embedding. `POST /api/devices/{id}/library/play-similar { track_id }` plays the track then its 49 nearest neighbours once (`422 not_playable` below 5 neighbours; scene operation `playSimilarTracks`, MCP `play_from_library` type `similar`). Exact PHP scan by default, optional sqlite-vec index (`SIMILAR_ENGINE=sqlite-vec`, `php artisan similar:index`); Full profile (`analysis` gate); `features.similar_tracks` in `GET /api/info` when an embedding exists; web: `Livewire\TrackSimilar`. `docs/architecture/similar-tracks.md` has the stage version (`2+ml`: analysed files are redone by the nightly backlog) and the measured times.

### Alerts

`App\Domain\Alerts\Alerts::raise($kind, $key, $severity, $title, $message, $deviceId, $data)` / `resolve($kind, $key)` is the one place conditions become alerts (stuck queue, silent scheduler, stale backup, unreachable storage, offline device, down listener, and the warn-only `network.exposed` when a request or `APP_URL` looks public, see `docs/architecture/network-exposure.md`; `mount.unavailable` per NAS mount of the Manager that is switched on but not mounted or readable, critical when it was lost, see `docs/architecture/manager.md`; `php artisan alerts:check` every minute). One active alert per `(kind, key)`; MQTT `remoment/alerts/{kind}` (not retained) and retained `remoment/alerts/active`; shown on `/settings/health`; MCP tool `alerts`. See `docs/architecture/alerts.md`.

```
GET  /api/alerts?since=&state=active|resolved|all&limit=   → { data: [Alert], summary }   newest first, `since` = id cursor
POST /api/admin/alerts/{id}/ack                            → { data: Alert }                  admin token; admin-wide, no per-user state
POST|DELETE /api/push/subscribe                            { endpoint, keys: { p256dh, auth } } | { endpoint }   open, 20/min; a browser's Web Push subscription for warning/critical alerts while closed (`App\Domain\Push`, `SendAlertPush`); endpoint must be https on a known push service, max 100 rows, no user; see docs/frontend/pwa.md
```

Alert `{ id, kind, key, severity: info|warning|critical, title, message, device_id, data, raised_at, resolved_at, acknowledged_at, state: raised|resolved }`.

### Device List & Detail

**`GET /api/devices`** — list all devices. While Spotify is routed to a mapped speaker, the Spotify virtual device is left out (see [Spotify routing](#spotify-routing)).

Response: `{ "data": [ DeviceListResource, … ] }`

**`GET /api/devices/{id}`** — full device detail including current playback.

Response: `{ "data": DeviceDetailResource }`

**DeviceListResource shape:**
```json
{
  "id": 1,
  "device_name": "Living Room",
  "device_brand_name": "Bang & Olufsen",
  "device_product_type": "Beolab 28",
  "device_driver_name": "ASE Music Player",
  "ip_address": "192.168.1.10",
  "state": "playing",
  "last_seen": "2026-07-05T12:00:00",
  "capabilities": ["media_controls", "volume_control", "source_control", "source_activation", "multi_room"],
  "mqtt_topic": "remoment/player/1"
}
```

`state` values: `playing` | `standby` | `paused` | `unreachable`

`capabilities` values: `media_controls` | `volume_control` | `radio_control` | `source_control` | `source_activation` | `multi_room` | `library_playback` | `seek` | `queue` | `queue_jump` | `queue_add` | `queue_next` | `queue_remove` | `queue_move` | `queue_clear` | `shuffle` | `repeat` | `like` | `power` | `sound_adjustment` | `bluetooth` | `device_info` | `battery` | `network_settings` | `wireless_speakers` | `wired_speakers` | `sleep_timer` | `digits` | `remote_control` | `stream_url` | `source_controls` | `move_playback` | `sync_group` | `sub_devices`

`multiroom` is `{ role, host, listeners }` (role `standalone` | `host` | `listener`, `host` and `listeners` as `{ id, name }`) or `null` while unknown — ASE devices only for now, read by their listener (`MultiRoomStatusInterface`, `MultiRoomUpdated` → cache + MQTT `/multiroom`, checked every 5 s); the device card shows a badge.

`battery` is `{ "level": 0-100, "charging": bool }` or `null` on every device (list and detail), and the `battery` capability is listed only once the device's listener has reported a battery (Mozart, portable Sonos; never ASE or Spotify) — a driver also covers mains-powered models.

Always check `capabilities` before calling a feature endpoint — calling an unsupported feature returns `422`. `capabilities` can change at runtime: a speaker Spotify is routed to also lists Spotify's playback capabilities (see below).

#### Spotify routing

While a speaker mapped to a Spotify Connect name (`/settings/spotify-connect`) plays Spotify, it and the Spotify virtual device behave as **one device**, everywhere (web, REST, MQTT), via `App\Domain\Device\SpotifyRouting`:

- Playback, progress, modes and state are reported on the **speaker's** id; the Spotify device stays in `standby`.
- On the speaker's id, `play`/`pause`/`stop`/`next`/`previous`, `seek`, `queue`, `shuffle`, `repeat` and `like` are executed by the **Spotify** driver on the Connect device. Volume, mute, power, sources, multiroom, radio and library playback stay with the speaker's own driver.
- The speaker's `capabilities` are its own plus Spotify's `media_controls`, `seek`, `queue`, `shuffle`, `repeat`, `like`; the `422` check uses the same merged set. They drop back when routing ends, so clients should re-read `capabilities` (or treat non-null `/modes` values as supported).
- `GET /api/devices`, `GET /api/clients/{api_token}/devices`, the approved `GET /api/clients/status/…` and the web `/devices` grid leave out the Spotify device — but only when the routed speaker is in that same list, so a client assigned only the Spotify device (or not the speaker) keeps it.
- Routing lasts while Spotify plays on the speaker, and while it is paused there unless the speaker reports playing something else by itself. When it ends (or moves to another speaker), the speaker's `modes` go back to what its own driver last reported, or all `null` if its driver reports none. The Spotify device's `modes` are cleared while its playback is on a speaker.

**DeviceDetailResource** — all fields from DeviceListResource plus `now_playing`:

```json
{
  "now_playing": {
    "track": {
      "id": "spotify:track:abc",
      "name": "Song Title",
      "source": "spotify",
      "duration": 213,
      "artist": { "name": "Artist Name", "images": [], "source": "spotify" },
      "images": ["https://…"],
      "meta": [{ "spotifyId": "spotify:track:abc" }]
    },
    "album": {
      "name": "Album Name",
      "images": ["https://…"],
      "released_at": "2023",
      "artist": { "name": "Artist Name" }
    },
    "radio": { "name": "Station Name", "genre": "Pop", "images": [] },
    "source": { "name": "Spotify", "category": "music", "sourceType": "MUSIC", "connector": "opt1", "jid": "…" },
    "state": "playing",
    "position": 42,
    "platform": "media",
    "type": "music",
    "endTime": "2026-07-05T12:03:33",
    "artwork": {
      "kind": "album",
      "proxy_512": "http://remoment.local/storage/artwork/abc123/512.jpg",
      "proxy_320": "http://remoment.local/storage/artwork/abc123/320.jpg",
      "proxy_120": "http://remoment.local/storage/artwork/abc123/120.jpg",
      "proxy_bg": "http://remoment.local/storage/artwork/abc123/bg_1024x600.jpg",
      "proxy_bg_320x480": "http://remoment.local/storage/artwork/abc123/bg_320x480.jpg",
      "proxy_bg_480x480": "http://remoment.local/storage/artwork/abc123/bg_480x480.jpg",
      "colors": ["#1a2b3c", "#4d5e6f", "#7a8b9c", "#0d1e2f", "#3c4d5e"],
      "safe_colors": ["#5d7fa3", "#4d5e6f", "#7a8b9c", "#4f86c4", "#3c4d5e"]
    },
    "modes": { "shuffle": false, "repeat": "off", "liked": null }
  }
}
```

`now_playing` is `null` when the device is in standby or unreachable. `artwork.kind` says what the image is: `album` (track/album cover), `radio` (the station's image from the stream or from its `image_url` in `/radio`, else a generated radio logo) or `source` (a generated logo for the source — Spotify, line-in, Bluetooth, TV, AirPlay/Cast, CD, or a music note — when nothing playing has an image). Logos have the same keys and go through the same pipeline as covers, so clients need no special case. A real image's `artwork` is absent on the first play of a new URL (processed asynchronously), present on all subsequent plays; logos are always present. Artwork URLs are prefixed with `APP_URL` (root-relative `/storage/…` when it is empty); clients should take the path from `/storage/` on and prefix `artwork_base_url` from `GET /api/info`. `proxy_512`/`proxy_320`/`proxy_120` are square covers; `proxy_bg` is a blurred, darkened 1024×600 background for the 7" landscape client and `proxy_bg_320x480` the same for the 3.5" portrait client and `proxy_bg_480x480` for a square 480×480 screen (extra background sizes are always `proxy_bg_{w}x{h}`; a client picks `proxy_bg_{width}x{height}` for its own screen (e.g. `proxy_bg_320x480` on the 3.5" portrait client) and falls back to `proxy_bg` (1024×600) when that key is absent); all are **baseline** JPEGs (ESP32 TJpgDec can't decode progressive). `safe_colors` are `colors` lightened to ≥65% lightness for text/accents on dark backgrounds. An entry cached before a key existed may lack it until regenerated. `radio` and `source` keys are absent when not applicable — use whichever is present to identify playback type. `modes` is always present: `shuffle` (bool), `repeat` (`off` | `all` | `one`) and `liked` (bool), each `null` when unknown or unsupported; the same object is pushed on MQTT `/modes`. `now_playing.waveform` (list of 0–100 heights: RMS stretched per track, not peaks relative to the loudest sample) is present on the detail endpoint when the playing track is in the library with an analysed own file (`App\Domain\Library\NowPlayingWaveform`, also used by the sticky player bar); the `/receiver` draws its progress from it.

### Media Controls

All control endpoints require the device to have the relevant capability. They return `503` if the device is unreachable and `422` if the capability is unsupported.

```
POST /api/devices/{id}/play
POST /api/devices/{id}/pause
POST /api/devices/{id}/stop
POST /api/devices/{id}/next
POST /api/devices/{id}/previous
```

Success response: `{ "status": "ok", "action": "play" }`

Error responses:
```json
{ "error": "unreachable", "message": "Device is not reachable." }          // 503
{ "error": "unsupported", "message": "This device does not support …." }   // 422
{ "error": "driver_error", "message": "The device did not respond: …" }    // 502
```

Every device endpoint answers through `GuardsDeviceAccess::withDriver()`: 503 unreachable (some, like sources and multiroom, skip that check), 422 `unsupported` (no such capability, or the driver can't do the operation, e.g. powering a Mozart on), 422 `invalid` (the driver rejected the value), 502 `driver_error` (anything else the device or driver threw).

### Volume

```
GET  /api/devices/{id}/volume          → { "volume": 45 }
PUT  /api/devices/{id}/volume          body: { "volume": 45 }   → { "volume": 45 }
```

Volume is an integer 0–100.

### Volume limits

A device can have a maximum volume and a lower one during quiet hours (`App\Domain\Device\VolumeLimits`, device meta `volume_limits`): `PUT /volume` and the web sliders set at most the limit (the answer is the level that was set), a device that reports a higher volume is set back by the `EnforceVolumeLimit` listener (first on `VolumeUpdated`), and an every-minute scheduled call applies quiet hours when they begin. `GET /api/devices/{id}/volume-limits` (open), `PUT /api/admin/devices/{id}/volume-limits` (admin token), `volume_limit` on every device; admin card on `/devices/{id}/settings`. See `docs/api/device-settings.md`.

### Mute

Part of `volume_control`.

```
GET  /api/devices/{id}/mute            → { "muted": false }
PUT  /api/devices/{id}/mute            body: { "muted": true }  → { "muted": true }
```

### Seek

Requires `seek` (Sonos, Spotify, Mozart).

```
PUT  /api/devices/{id}/seek            body: { "position": 90 }  → { "status": "ok", "position": 90 }
```

`position` is an absolute offset in seconds within the current track.

### Shuffle, Repeat, Like

Require `shuffle` (Sonos, Spotify, Mozart), `repeat` (Sonos, Spotify, Mozart) and `like` (Spotify) respectively.

```
PUT  /api/devices/{id}/shuffle         body: { "shuffle": true }            → { "shuffle": true }
PUT  /api/devices/{id}/repeat          body: { "repeat": "all" }            → { "repeat": "all" }
PUT  /api/devices/{id}/like            body: { "liked": true }              → { "liked": true }
```

`repeat` is `off` | `all` (the queue/context) | `one` (the current track). `like` saves the playing track to (or removes it from) the Spotify library; it needs the `user-library-modify` scope, so a Spotify connection made before it was added must be reconnected at `/settings`. A successful change is written to `now_playing.modes` and MQTT `/modes` right away, then kept in sync by the device listener. On a speaker Spotify is routed to, these go to Spotify (see [Spotify routing](#spotify-routing)).

### Power

Requires `power` (B&O ASE, Mozart).

```
PUT  /api/devices/{id}/power           body: { "on": false }                → { "on": false }
```

`on: true` wakes the device, `on: false` puts it in standby. Mozart can only be put in standby: `on: true` returns `422` `{ "error": "unsupported", "message": "This device cannot be switched on remotely." }`. The resulting state arrives through the listener (`state` / MQTT `/state`).

### Device settings

Per-device settings behind their own capabilities; full shapes in `docs/api/device-settings.md`. Same errors as the controls (`503` unreachable, `422` unsupported / `invalid` value, `502` the device failed or didn't apply the change).

```
GET|PUT  /api/devices/{id}/sound-adjustment   → { bass: {value,min,max,step}, treble: {…}, loudness }   PUT { bass?, treble?, loudness? }   (capability `sound_adjustment`; ASE, Mozart, Sonos)
GET|PUT  /api/devices/{id}/bluetooth          → { enabled, discoverable, reconnect_mode, reconnect_modes, writable, devices: [{id,name,address,connected}] }   PUT { discoverable?, reconnect_mode? }   (capability `bluetooth`; ASE; Mozart lists only, `writable: false`)
DELETE   /api/devices/{id}/bluetooth/devices/{deviceId}   forget a paired device
GET|PUT  /api/devices/{id}/info               → { name, product_type, firmware, mac_address, renamable }   PUT { name }   (capability `device_info`; ASE, Mozart, Sonos — Sonos can't rename)
```

The web page `/devices/{id}/settings` (guest-accessible; network and rename need the admin login, `App\Livewire\DeviceSettings`) shows one card per capability the device has.

```
GET|PUT  /api/devices/{id}/sleep-timer   → { value, min, max, step, writable }   PUT { minutes } (0 cancels; 0–60)   (capability `sleep_timer`; ASE — the BeoPlay V1 lists it but is `writable: false`)
POST     /api/devices/{id}/digits   body { digits: "105" } → { status, digits }   number keys for the active TV / set-top box source, sent in order (capability `digits`; ASE VideoPlayerDriver = BeoPlay V1, Philips TV)
GET|POST /api/devices/{id}/keys   → { supported_keys: ["up", …] } | body { key: "ok" } → { status, key }   remote keys of a TV (capability `remote_control`; Philips Android TV via `POST /6/input/key`, ASE not yet); `RemoteKey` names up/down/left/right/ok/back/home/menu/source/info/guide/exit/play/pause/stop/next/previous/fast_forward/rewind/volume_up/volume_down/mute/channel_up/channel_down/red/green/yellow/blue; 422 `unsupported` for a key outside `supported_keys`, 422 `invalid` for an unknown name; `supported_keys` also in the device detail; MCP `remote_keys`; web: the Remote card on the device page
GET  /api/devices/{id}/jellyfin   → { active, continue_watching: [Tile], next_up: [Tile] }   Jellyfin on a Philips TV with a Jellyfin key (Settings › Services): what to continue or watch next; `POST …/jellyfin/play { item_id, position_ticks? }` starts it in the Jellyfin app on the TV; MCP `jellyfin`; see `docs/api/jellyfin.md`; web: the Jellyfin card on the TV's page. These two routes are wrappers over the generic media-server API (workstream 15, `App\Domain\MediaServers\DeviceMedia`, `docs/api/media-servers.md`, `docs/architecture/media-servers.md`): `GET /api/media-servers` → `{ data: [{ id, name, driver, label, base_url, enabled, configured, capabilities }] }`, `GET /api/devices/{id}/media` → `{ active, servers: [{ id, name, driver, ok }], continue_watching: [MediaItem], next_up: [MediaItem] }` (every enabled, configured server whose driver can play on the device, merged; `MediaItem { id: "3:abc" (server:item), server_id, item_id, kind, title, subtitle, year, image, progress, resume_position (s), duration, library }`), `POST /api/devices/{id}/media/play { item, server?, position? (s) }`; a server is a row of `media_servers` (the one Jellyfin setting of Settings › Services is migrated into it, the key stays in the credentials layer), a type a driver class + an entry in `config/media-servers.php`; Jellyfin is the only driver until after 1.0; MCP `media_servers`; the card is `Livewire\MediaShelf`
GET      /api/devices/{id}/network   → active interface, wired/wireless status and addresses, known Wi-Fi networks (never a passphrase)   (capability `network_settings`; ASE)
PUT      /api/devices/{id}/network/interface | /network/wired | /network/wifi   → { status: "ok" } — not read back, the device may change address
GET      /api/devices/{id}/wireless-speakers   POST …/wireless-speakers/scan { action: start|stop }   (capability `wireless_speakers`; WiSA: **only the BeoSound Moment**, via `wisa => true` in config/devices.php / `HardwareFeatures`)
GET      /api/devices/{id}/wired-speakers   PUT …/wired-speakers/{pl_1|pl_2} { type?, sound? }   (capability `wired_speakers`; which speaker type is on each wired output: **only models with external speakers**, Essence and Moment, via `speaker => 'external'` in config/devices.php / `HardwareFeatures`)
```

### Move playback

`POST /api/devices/{id}/move` `{ to_device_id, stop_source? }` continues what plays on `{id}` on another device (capability `move_playback`, cache-only check). Library tracks keep queue and position (play, wait for `playing`, seek), radio restarts the station, Spotify uses Spotify's own transfer to a Connect-mapped speaker; line-in/TV/CD/Bluetooth/AirPlay inputs are `422 not_movable`. Code `App\Domain\Playback`; see `docs/api/playback-move.md`.

### Sync groups (fake multiroom)

`/api/sync-groups` makes a leader + members group for devices without native multiroom (capability `sync_group`): members start on the leader's queue (the move machinery) and `SyncGroupMirror` keeps them in step from the leader's events: state, track changes, a seek of a member that drifted more than `sync.drift_threshold` (2 s). Library tracks and radio, not Spotify; precision about ±2 s. UI: "Sync groups" on `/multiroom`. See `docs/architecture/sync-groups.md` and `docs/api/sync-groups.md`.

### Sub devices

A device connected to another device, its host (a Chromecast on a TV's HDMI input, a BeoLink Converter a player borrows), stays a full device and is tied to the host by a row in `device_links` (`App\Models\DeviceLink`, `App\Domain\SubDevices\DeviceLinks`: `device_id` = the sub device, `host_device_id`, `kind` borrow|input|source, `via` = the host's input or source id, `meta` JSON). One level deep (a device is a sub device or a host, never both), many to many, deleting a device ends its links. `GET /api/devices/{id}` has `host` (`{id, name, via}`|null) and `sub_devices` (`[{id, name, via, active}]`, `active` null while unknown), a host has the `sub_devices` capability; `LinkState::active()` reads the host's `ActiveInput` cache, so a host driver must implement `ReportsActiveSource` for a known state. A model with `standalone => false` in `config/devices.php` (a Chromecast) has no card in the `/devices` grid, the host's card lists it while the link is active. While a link is active the host is routed to its sub device: `App\Domain\Device\DeviceRouting` (generalised from `SpotifyRouting`, rules `SubDeviceRoute` then `SpotifyRoute`; always ask it for the driver of a playback command and for capabilities, never the device's own driver) sends transport, seek, queue and shuffle/repeat/like to the sub device, volume, power and other sources stay with the host, the host shows the sub device's now playing, its source list marks the link (`sub_device`, or an entry of its own), and `meta.on_activate` / `on_deactivate` run on the sub device when `TrackSubDeviceLinks` (host events, whoever changed the input) sees the link change. Links are managed by the admin on the device edit page ("Connected devices", `Livewire\ConnectedDevices`), at `/api/admin/device-links` and with MCP `device_links`; the `meta` (actions, follow rule) only through the API and MCP. The follow rule (`App\Domain\SubDevices\FollowRule`, link `meta.follow` off|from_standby (default)|always, `follow_off_after`) makes the host select an `input`/`source` link when its sub device starts playing (a cast wakes the Beoplay V1 on `hdmi_1`), after `config/sub-devices.php`'s debounce, and turns the host off after `follow_off_after` minutes only if the rule woke it (`meta.state.woke_host`); `SwitchToDefaultSource` leaves a host the rule just switched alone. See `docs/architecture/sub-devices.md` and `docs/api/sub-devices.md`; hardware behaviour is unverified.

### Spotify stream

`stream_url` (UPnP, Sonos, ASE unverified) plays a live MP3 stream; the opt-in librespot + Icecast containers turn Spotify Connect into one (`docs/architecture/spotify-stream.md`).

```
GET  /api/spotify-stream                  → { enabled, name, url }
POST /api/devices/{id}/spotify-stream     → { status, url, receiver }   (needs LIBRESPOT_ENABLED and `stream_url`; the user then picks the receiver in Spotify)
POST /api/librespot/event                 the container's --onevent hook, header X-Librespot-Secret; becomes the speaker's now playing
```

### Source controls

`source_controls` is reported while the playing source has controls a transport can deliver (CD changer, tape, FM presets); full description in `docs/api/source-controls.md`.

```
GET  /api/devices/{id}/controls            → { "profile": "cd", "label": "CD", "controls": [ { id, label, icon, type, group }, … ] }
POST /api/devices/{id}/controls/{control}  → { "status": "ok", "control": "next_disc" }
```

Profiles are in `config/control-profiles.php`; `App\Domain\Control\SourceControls` resolves the profile from the active source and sends commands through a `ControlTransport`. `422` for an unknown control or none for the source, `503` unreachable, `502` the transport failed.

### Up Next (queue)

Editing (add, play next, remove, move, clear) has its own capabilities and endpoints, see `docs/api/queue.md` and [Queue editing](#queue-editing).

Requires `queue` (Sonos, Spotify).

```
GET  /api/devices/{id}/queue?limit=20  → { "up_next": [ QueueItem, … ] }
```

`limit` is 1–100 (default 20). Returns only the tracks after the current one. The list is empty when the device isn't playing from a queue (radio, line-in).

**QueueItem shape:** `{ "name": "…", "artist": "…"|null, "album": "…"|null, "image": "https://…"|null, "duration": 200|null, "uri": "…"|null, "artwork": { hash, proxy_120, proxy_320, colors, safe_colors }|null }`. `artwork` is the small library artwork object (see Library): the item's `image` is processed through the artwork pipeline (queued at most once an hour per image, never waited for), so it is `null` until processed.

Validation errors return Laravel's standard `422` `{ "message", "errors" }`. Unknown devices and routes return `404`.

### Queue editing

```
POST   /api/devices/{id}/queue              { type: track|album|artist|playlist, id, next?: bool }   → { status, next, tracks }   (`queue_add`; `next` needs `queue_next`)
DELETE /api/devices/{id}/queue/{position}   ?expect=<uri>                                            → { status, position }       (`queue_remove`)
PUT    /api/devices/{id}/queue/{position}   { to, expect? }                                          → { status, position }       (`queue_move`)
DELETE /api/devices/{id}/queue                                                                       → { status }                 (`queue_clear`: everything after the current track)
```

`position` is the up-next position (1 = first of `GET …/queue`). `expect` is the `uri` the client saw there: when the queue has changed, the driver refuses (`422 invalid`) instead of editing the wrong entry, and the client re-reads. Contracts `QueueAddInterface`, `QueuePlayNextInterface`, `QueueRemoveInterface`, `QueueMoveInterface`, `QueueClearInterface` (all in `DELEGATED_CONTRACTS`); the drivers take the tracks they can play (a DLNA stream, a Spotify URI) from `Domain\Library\QueueTracks` and skip the rest. Support: UPnP/ReMoment (`Common\EditsDeviceQueue` on `DeviceQueue`) all five, Sonos all five, ASE add/remove/clear (play next and move unverified on hardware), Spotify add only, Mozart add, play next and clear (no queue read/remove/move in its spec). Web: `POST /devices/{device}/queue` (`devices.queue.add`) for the album page's buttons; the player bar's Up next panel and the device page's Up Next card remove, move and clear via `Livewire\Concerns\EditsQueue`; MCP `queue` tool actions.

### Sources

```
GET  /api/devices/{id}/sources         → { "sources": [ AvailableSource, … ] }
POST /api/devices/{id}/sources/activate  body: { "source_id": "MUSIC" }   → { "status": "ok", "source_id": "MUSIC" }
```

`GET` fetches live data from the device and refreshes the `device_sources` table; hidden/sort preferences are preserved by `source_id`. The response is ordered by `sort_order` and carries `hidden` and `sort_order`; `?visible=1` drops hidden ones (use it for pickers). `PUT /api/admin/devices/{id}/sources/{source_id}` (admin token) body `{ "hidden": bool, "sort_order": int }` changes them. QPlay and borrowed sources start hidden. UX rules: `docs/ux/sources.md`.

**AvailableSource shape:**
```json
{
  "source_id": "MUSIC",
  "friendly_name": "Music",
  "source_type": "MUSIC",
  "category": "music",
  "in_use": true,
  "borrowed": false,
  "provider_jid": "provider@jid.example",
  "provider_name": "Spotify",
  "input": "HDMI 5",
  "signal": true,
  "active": true,
  "hidden": false,
  "sort_order": 0
}
```

`in_use` is not "selected" (ASE reports it true for nearly every source). `active` is the selected source and is absent when the device cannot tell; clients then match `now_playing.source`. `input` is the physical connection (TV devices: "HDMI 2"), absent for digital sources; `signal` is the device's signal flag for the source (B&O TVs). A TV (driver with `VideoDeviceInterface`) gets Input/Apps grids and a Power on button that wakes it by activating a source; see `docs/architecture/beovision-tv.md`.

### Radio

```
GET  /api/devices/{id}/radio                → { "stations": [ RadioStationItem, … ] }
POST /api/devices/{id}/radio/{station_id}   → { "status": "ok", "station": "Radio 1" }
```

`station_id` is the `RadioStation` model ID. The device must have `radio_control` capability and a compatible radio platform. `GET` lists exactly the stations with an identifier for the device's platform (`beoradio` for ASE/Mozart, `tunein` for Sonos, `url` for generic UPnP). **Native identifiers win:** a station with a `beoradio` / `tunein` id plays through the speaker's own radio even when it also has a stream `url`; the plain stream URL (`PlayStreamUrlInterface`, see `docs/architecture/spotify-stream.md`) is only the fallback on ASE and Sonos and the only way on UPnP. Stations are added by hand or from the radio-browser.info search on `/radio/create` (`Domain\Radio\RadioBrowser`, `Livewire\RadioStationSearch`); a UPnP renderer's now playing for such a station is the station plus the song from the stream's ICY title (`Domain\Radio\IcyReader`, polled by `Jobs\PollRadioTitle` through `StreamTitles`, one chain per station while a device plays it; Sonos and ASE report their own titles), ordered by name like `/radio`; `422` without `radio_control`, `503` while unreachable (building the driver may contact the device). **Radio relay** (workstream 17, `docs/architecture/radio-relay.md`): a station the renderer cannot play itself (HTTPS, AAC, playlists, no DNS) is converted to MP3 by `php artisan radio:relay` (one ffmpeg per station under `nice`, on demand, idle stop, `RADIO_RELAY_*`, compose profile `radio-relay`) and played from Icecast; `radio_relay` device meta auto|always|never decides. The play answer carries `"relay": true` and `now_playing.radio.relay` is true while it plays that way; the mount shows the station's current title (`RadioRelay::pushTitle`); `GET /api/radio/relays` → `{ enabled, manager, max, data: [{ station_id, station, state: running|restarting, since, listeners, failures, retry_at, mount, url }] }` (MCP `radio` with `relays: true`); a relay that dies soon after starting three times in a row raises the `radio_relay` alert. Pi 3 figures are not measured yet (`scripts/radio-relay/measure.sh`).

**RadioStationItem shape:** `{ "id": 3, "name": "Radio 1", "genre": null, "favorite": false, "artwork": { "kind": "radio", "proxy_512": "…", … } | null }`. `genre` is the station's genre when it was added from the radio-browser.info search, else `null`. `favorite` is household-wide (`radio_stations.favorited_at`); `PUT /api/radio/{station_id}/favorite` with `{ "favorite": true }` → `{ "favorite": true }` sets it. The `/radio` web page lists favorites first; the API list stays by name. `artwork` is the same object as `now_playing.artwork`: the station's `image_url`, else the generated radio logo. An `image_url` that isn't processed yet gives `null` and is queued (at most once an hour per image), so the request never waits for a download.

### Multiroom

```
GET    /api/devices/{id}/multiroom                                → joinable + listening devices
POST   /api/devices/{id}/multiroom/join  body: { "host_device_id": 2 }  → { "status": "ok", "joined": "Kitchen" }
DELETE /api/devices/{id}/multiroom/leave                          → { "status": "ok" }
```

**Multiroom GET response:**
```json
{
  "joinable": [
    { "id": 2, "device_name": "Kitchen", "state": "playing" }
  ],
  "listeners": [
    { "id": 3, "device_name": "Bedroom", "state": "playing" }
  ]
}
```

`joinable` — devices currently playing that this device could join.
`listeners` — devices currently joined to this device's session.

For Sonos, `joinable` is always empty (returns `[]`); the UI falls back to showing all Sonos devices.

### Library

Browse, favorites and playback for clients; full shapes in `docs/api/library.md`. Artwork in these responses is the small object `{ hash, proxy_120, proxy_320, colors, safe_colors }` or `null` while unprocessed (queued at most once an hour per image). `colors`/`safe_colors` are the full artwork object's palette from the cover's cache entry (`null` when the entry expired or predates it); list endpoints read the entries of a page in one cache round trip (`LibraryItemArtwork::preload`).

```
GET  /api/library/libraries[?device_id=]    → { data: [{ id, name, kind, album_count, artist_count, playlist_count, artwork, playable? }] }   music libraries (files = Local own files, dlna; LibrarySources::LIBRARIES; Spotify is not a library) with content, not hidden for ?client=; `playable` only with ?device_id= (can that device play it: LibrarySources::LIBRARIES `device` check, 422 unknown device); a library id is also a valid ?scope= on the browse endpoints (next to local|streaming|all)
GET  /api/library/recent-items?limit=30   → { data: [{ type: album|playlist|radio, library, played_at, album, playlist, station }] }   mixed recently played, newest first
GET  /api/library/artists?cursor=&sort=&letter= → { data: [ArtistItem], next_cursor }   sort name (default, "The " ignored) | plays (most played first, plays of visible tracks in the scope, unplayed last, ties name then id), 50/page, only artists with albums; letter A-Z/# starts there (ignored for plays)
GET  /api/library/albums?cursor=&sort=&letter= → { data: [AlbumItem], next_cursor }   sort name (default) | artist | year | added | plays, 60/page, only albums with tracks; letter A-Z/# starts at that letter of the name/artist sort (ignored for year/added/plays)
GET  /api/library/tracks/{id}/mixes-with?cursor=&scope=&device_id= → { canonical_id, source: {bpm, camelot}, data: [SearchTrack + bpm, camelot, key_relation, tempo_relation, tempo_diff_percent], next_cursor }   "Mixes well with this" (`Domain\Library\MixesWith`, Full profile): tempo within 6 % (also half/double) and a Camelot key that is the same, relative or one step away, never the same recording; sorted key relation (same, relative, adjacent), then tempo difference, then direct before half/double, then id; 422 `not_analysed`; MCP `browse_library` kind `mixes-with`
GET  /api/library/artists/{id}             → { id, name, favorite, genres, details (bio, links, images, mood, Last.fm stats …), albums: [AlbumItem] }   newest year first, then name
GET  /api/library/albums/{id}?device_id=   → { id, name, artist: {id, name}, year, artwork, favorite, genres, details (label, release type, mood, rating, credits …), playable, tracks: [{ id, name, duration, playable, details }] }
GET  /api/library/random-album?scope=&device_id= → { data: AlbumItem }   one random album with a visible track in the scope (library id, local|streaming|all; ?client= hides as elsewhere; 404 when the library has none, 422 unknown scope); with device_id prefers an album that device can play, else any; random offset into the id order, not loading rows
GET  /api/library/genres                   → { data: [{ slug, name, artist_count, album_count, colors }] }   canonical genres, most artists first; `colors` = up to 3 hex colours the genre's covers share (`GenreColors`: artwork-cache palettes, first 2 per cover, 32-step buckets, vivid first = HSV s>=0.25 and v 0.2-0.95, 200-album sample, cached 1 h, `[]` when none)
GET  /api/library/genres/{slug}            → { slug, name, colors, artists: [ArtistItem], albums: [AlbumItem] }   up to 100 of each
GET  /api/library/playlists?cursor=[&scope=] → { data: [PlaylistItem + library, external, local_track_count, local_coverage], next_cursor }   recently played (last_played_at) first, then by name, 50/page, only playlists with tracks; scope dlna/local also lists Spotify playlists with local_coverage >= `PlaylistCoverage::MIN_COVERAGE` (0.4) as `external: true`
GET  /api/library/playlists/{id}?device_id=&library= → { id, name, owner, last_played_at, artwork, mode, mode_library, sources, track_count, local_track_count, local_coverage, smart, rules, rules_summary, playable, tracks: [{ id, name, artist_name, duration, artwork, playable, missing_locally (local mode only) }] }   playlist order, first 200; track artwork = album cover (one preload, no N+1); rules/rules_summary only for smart playlists (SmartPlaylistDescriber)
GET  /api/library/recent?limit=30          → { data: [AlbumItem] }   most recently played distinct albums (limit 1–100)
GET  /api/library/tracks?cursor=&sort=&letter= → { data: [SearchTrack], next_cursor }   all visible tracks, sort name (default) | artist | added | plays, 50/page
GET  /api/library/favorites                → { albums: [AlbumItem], artists: [ArtistItem] }   most recently favorited first
PUT  /api/library/albums/{id}/favorite     body: { "favorite": true }   → { "favorite": true }
PUT  /api/library/artists/{id}/favorite    body: { "favorite": true }   → { "favorite": true }
```

ArtistItem `{ id, name, album_count, artwork, favorite }`; AlbumItem `{ id, name, artist_name, year, artwork, favorite }`; PlaylistItem `{ id, name, track_count, owner, last_played_at, artwork }` (`owner` = Spotify owner or `null`; `artwork` = the generated playlist cover, see [Artwork](#artwork-caching--processing)). A playlist counts as played when started from the server (API or web), which sets `playlists.last_played_at`. Favorites are household-wide (`favorited_at` on `albums`/`artists`; the web album/artist pages toggle them too). There is one record per artist/album/track across sources (see [Library identity](#library-identity)); ids of records merged by `library:merge-duplicates` keep working: `id_aliases` (written by `LibraryMerger`, resolved by `App\Domain\Library\IdAliases`) makes the detail, favorite, play and queue endpoints and the web routes (301) act on the surviving record, and the detail responses (and favorite responses) carry `canonical_id` (the surviving id, equal to `id` when nothing was merged) for clients to update their cache; an unknown or removed id stays `404` (`422` in a body), and merges made before this existed are not covered.

**Playing from the library**

```
POST /api/devices/{id}/library/play            body: { "track_id": 42 }                                        → { "status": "ok", "track": "Song Title" }
POST /api/devices/{id}/library/play-playlist   body: { "playlist_id": 3, "start_track_id": 12, "shuffle": false } → { "status": "ok", "playlist": "…" }
POST /api/devices/{id}/library/play-album      body: { "album_id": 12, "start_track_id": 34, "shuffle": false } → { "status": "ok", "album": "…" }
POST /api/devices/{id}/library/play-tracks     body: { "scope"?, "client"? }                                   → { "status": "ok", "queued": 100 }   shuffle the whole library (random sample of 100 playable tracks)
POST /api/devices/{id}/library/play-artist     body: { "artist_id": 5, "shuffle": true }                        → { "status": "ok", "artist": "…" }
```

`App\Domain\Library\LibraryPlayback` (shared with the web play buttons) tries **DLNA** first — the device's `LibraryPlaybackInterface` driver, for tracks with a `dlna_url` — then **Spotify** — when Spotify is connected and the device is the Spotify device or mapped to a Spotify Connect name, for tracks with a Spotify URI (`external_id` `spotify:track:…`): (the track's own `external_id`, or kept as `external_id` metadata with source `spotify` on a merged track): an album plays as its Spotify album context (URI looked up once, stored as album metadata `spotify_album_uri`), an artist as a list of track URIs, a Spotify playlist as its context (offset at `start_track_id`, or random when shuffling); other playlists play their tracks like an album. A playlist that belongs to a library (Spotify: `playlists.source`/`external_id`, `LibraryPlayback::playlistLibrary`) reports `sources` = only that library and is `playable` (playlist and tracks, with `device_id`) only where the device can play that library (`LibrarySources::playableOn`); `play-playlist` on a device that can't answers `422 not_playable` (message names the library) instead of playing merged local copies. Local/smart playlists keep track-derived `sources`/`playable`. **Playlist modes** (`PlaylistMode`, `PlaylistCoverage`; docs/api/library.md "Playlists of another library, played locally"): a playlist plays in exactly one mode, decided by the server from the optional `library` (the library the client browsed in; detail query and play-playlist body): `library` mode (its own library plays it, the default) or `local` mode (a playlist of another library opened from Local, `library=dlna`: only tracks with a Local copy are queued in order, the rest skipped; response `{ queued, skipped, mode }`, `mode` also in library mode). A `library` that makes no sense for the playlist is a 422 validation error; local mode on a device that can't play Local, or with no local track, is `422 not_playable`. `local_coverage` (local tracks / all tracks, 2 decimals) comes from one aggregate query per page; Local-scope lists include other-library playlists at >= 0.4 as `external`. `start_track_id`/`shuffle` are optional on play-album and play-playlist. A device has `library_playback` when either path exists. Errors: `422` `not_playable` (play-album/artist/playlist: neither path works), `422` `no_dlna_url` (`/library/play` on a DLNA device without a stream for the track), `422` `unsupported` (`/library/play`, `/play-playlist` without `library_playback`), `503` unreachable, `502` `driver_error` (device or Spotify failed, or Spotify doesn't list the mapped speaker).

**Discography and wishlist** (MusicBrainz release-groups of library artists, workstream 3; `docs/api/availability.md`, design in `docs/architecture/availability-layer.md`). Open, but never for a client token (`403`); opt-in, so nothing above changes without them.

```
GET /api/library/artists/{id}/discography?availability=owned|streamable|missing&wanted=1&ignored=1&device_id=   → { data: [CatalogueItem] }   albums and EPs, newest first
GET /api/library/wishlist?cursor=                                                                              → { data: [CatalogueItem], songs: [WantedSong], next_cursor }   (songs: first page)
GET /api/library/new-releases?days=90                                                                    → { data: [CatalogueItem + release_date] }   new/upcoming albums and EPs of your artists the library lacks (`catalogue:new-releases`, daily: ListenBrainz feed triggers the MusicBrainz sync)
GET /api/library/catalogue/{id}                                                                                → CatalogueItem
GET /api/library/artists/{id}?include=discography                                                              → artist detail + discography
GET /api/library/albums?availability=owned|streamable                                                          → narrowed list (`missing` is 422: missing albums have no album row)
GET  /api/library/external/{provider}/search?q=&type=artist|album|track → { data: [ExternalItem] }   browse an external service (Spotify; `config/availability.php`), any artist; `in_library` says what the library has; never stored
GET  /api/library/external/{provider}/artists/{id} | /albums/{id}        → { artist|album, albums|tracks: [ExternalItem] }   an artist also has the popular tracks in the web (Last.fm top tracks matched on Spotify, else Spotify search)
GET  /api/library/external/{provider}/saved-albums | /liked-tracks | /recent   → { data: [ExternalItem] }   what the account saved, liked and played recently (live; the Streaming scope of `/library`)
GET  /api/library/external/{provider}/playlists | /playlists/{id}        → the account's playlists (live) / { playlist, tracks | null }   Spotify only shares the tracks of playlists you own or collaborate on
POST /api/devices/{id}/library/play-external { provider, type: artist|album|track|playlist, id, shuffle? } → plays the album/artist/playlist context or track without importing (`LibraryPlayback::playExternal`; 422 not_playable)
POST   /api/library/wanted-tracks  { artist, title, album?, image_url?, source? } → WantedSong   wish for a song (history, radio, by hand); one per artist+title; closes when the library track has an own file or DLNA copy
DELETE /api/library/wanted-tracks/{id}                                              → { status: ok }
PUT /api/library/catalogue/{id}/wanted  { wanted }  → { wanted }      household-wide wish (clears ignored); closes by itself when the album gets an own file
PUT /api/library/catalogue/{id}/ignored { ignored } → { ignored }    "I don't want this" (clears the wish)
```

CatalogueItem `{ id, release_group_mbid, title, type: album|ep, year, artist, state: owned|streamable|known, album_id|null, wanted, ignored, fulfilled, availability: [{ provider, library, status, playable? }], artwork }`; MCP tool `catalogue`. Filled by `php artisan catalogue:sync` (nightly, queue `catalogue`).

**Physical collection** (workstream 6; `docs/api/physical.md`). Open, closed to client tokens. An item makes its album owned and closes a wish on its release-group.

```
GET|POST /api/library/physical   ?medium=&q=&ripped=&cursor=  |  { artist, title, medium, year?, barcode?, location?, release_group_mbid?, … }
GET|PUT|DELETE /api/library/physical/{id}                      PUT also takes `ripped`
GET /api/library/physical/lookup?barcode=                      → { data: [Release] }   MusicBrainz releases for a barcode, to choose from
POST /api/library/physical/import  (file|csv, default_medium?, mapping?) → preview { token, format discogs|generic, summary, data: per row new|lookup|duplicate|invalid }; POST …/import/{token}/confirm → { added, queued_lookups, … }   CSV import (`Domain\Catalogue\PhysicalImport`): Discogs export or any header-mapped CSV, duplicates by barcode or artist+title+medium, barcodes looked up by queued `LookupPhysicalBarcode` jobs behind the `musicbrainz` limiter
```

MCP tool `physical` (`import` too); web `/library/collection` (Livewire `PhysicalCollection`, with the CSV import card); table `physical_items`, `App\Domain\Catalogue\PhysicalItems`. A file that arrives with the MusicBrainz release id (`MUSICBRAINZ_ALBUMID`, what the ripper writes) or barcode of a **CD** item marks it `ripped` and links its album (`FileIndexer::attach` → `Domain\Catalogue\PhysicalRipLink`); see `docs/api/physical.md`.

### Scenes

`/api/scenes` (open like playlists; no accounts): a scene is a list of steps `{operation, device_id, params}` (an operationId of `docs/api/openapi.yaml` from `SceneOperations`) or `{type: "delay", seconds}`, run in order by `App\Domain\Scenes\SceneRunner` through `ApiBridge`, so each step answers like a client call. Steps are validated on save and again before a run; `stop_on_error` decides whether a failing step ends it; the result per step and `last_run` are stored on the `scenes` row. A scene with delays is queued (`RunScene`, 202), one without runs in the request (200). `schedule_time`/`schedule_days` are run by the every-minute task `scenes-run-due` (profile feature `scenes`). A scene also starts from **triggers** (`triggers` + `trigger_debounce`: an MQTT topic filter, subscriber `php artisan scenes:listen` = supervisor program `scene-triggers`; or a device state/source change via `Listeners\Device\TriggerScenes`; always queued, `last_run.source` `trigger`, debounce plus per-scene loop protection in `App\Domain\Scenes\SceneTriggers`), has an optional **alarm** (`alarm`: start volume, the steps, then a volume ramp over N minutes), and devices have a server-side **sleep timer** `GET|POST|DELETE /api/devices/{id}/sleep` (fade, stop or standby, volume restored; distinct from the native `sleep_timer`); the alarm ramp and the sleep timer are `device_timers` rows advanced by delayed `AdvanceDeviceTimer` jobs (`App\Domain\Scenes\DeviceTimers`, config `config/scenes.php`, needs a real queue). MCP `scenes` (also `sleep`, `sleep_status`, `sleep_cancel`). See `docs/api/scenes.md`.

```
GET|POST /api/scenes     GET|PUT|DELETE /api/scenes/{id}     POST /api/scenes/{id}/activate
```

### Announcements, tags and the party page

- **Announcements** (`docs/api/announcements.md`): `POST /api/announcements { clip, device_ids, volume? }` plays a short MP3 clip (`GET /api/announcements/clips`; the built-in `chime` or an upload through `POST /api/admin/announcements/clips`) on devices with `stream_url`; `App\Domain\Playback\Announcer` remembers what played (queue and position, radio, Spotify, input, standby, volume) and the delayed job `RestoreAfterAnnouncement` puts it back with `PlaybackMover::apply()`. Scene operation `announce`, MCP `announce`, `/settings/announcements`. Unverified on hardware.
- **Tap to play** (`docs/api/tags.md`): `POST /api/tags/{uid}/play { device_id?, client? }` plays what an NFC tag or QR code points to (album, artist, playlist, track, radio station, physical item; table `play_tags`, `App\Domain\Tags\PlayTags`, played through `ApiBridge` so the play endpoints stay the one implementation); an unknown uid is `404 unknown_tag` and kept 10 minutes for the admin; `/api/admin/tags` CRUD, `/settings/tags`, QR page `/t/{uid}`, MCP `tags`.
- **Party page** (`docs/frontend/party.md`): the admin switches a guest queue on per device (card on `/devices/{id}/settings`, device meta `party`); `/party/{token}` is a page without the app layout where guests search and add tracks to that device's queue (`Livewire\PartyQueue`: only `add`, limited per visitor cookie and per address). Web only, gated with the library.

### Client Device API

Five endpoints used by firmware and software clients to self-register, poll for admin approval, retrieve assigned devices, and pre-cache artwork.

```
POST  /api/clients/register                           → registration_token + pairing_code
GET   /api/clients/status/{registration_token}        → pending | approved + api_token + devices
GET   /api/clients/{api_token}/devices                → assigned device list
PUT   /api/clients/{api_token}/heartbeat              → updates IP, firmware, last_seen_at
GET   /api/clients/{api_token}/artwork?cursor=        → processed library covers to pre-cache, by hash
```

The artwork list is `{ data: [{ kind, hash, proxy_320, proxy_120, proxy_bg, proxy_bg_320x480, proxy_bg_480x480 }], next_cursor }`: the eight source logos (`kind: "source"`, first page only), processed playlist covers that aren't just an album's cover (`kind: "playlist"`: composites and playlists' own images, recently played first, first page only), then library albums whose cover is fully processed (`kind: "album"`), most recently played first, then the never-played rest by id. 200 albums are scanned per page (an offset cursor), so pages can be short; follow `next_cursor` until `null`. Unprocessed covers are skipped, not queued.

See `docs/api/client-devices.md` for full request/response shapes, the registration flow, firmware implementation notes, and an Arduino sketch outline.

---
