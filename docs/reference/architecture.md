# Architecture notes (detailed)

### Event-Driven Core

Defined in `app/Providers/AppServiceProvider.php`:

- `NowPlayingUpdated` → `UpdateDeviceCache`, `StorePlaybackHistory` (queued), `PublishNowPlayingToMqtt`, `DispatchArtworkProcessing`
- `ProgressUpdated` → `UpdateDeviceCache`, `PublishProgressToMqtt`
- `NowPlayingEnded` → `UpdateDeviceCache`, `ClosePlaybackHistory`
- `VolumeUpdated` (volume + optional `muted`) → `UpdateDeviceCache`, `PublishVolumeToMqtt`
- `BatteryUpdated` (`BatteryStatus`: level, charging) → `UpdateDeviceCache` (`Battery`), `PublishBatteryToMqtt`. Mozart's listener reads `GET /api/v1/battery` on connect and takes the WebSocket battery notifications; Sonos's polls `:1400/status/batterystatus` (`SonosBattery`) every 60 s — a speaker without a battery simply never reports one. The device card shows an icon and percentage (`<x-battery>`).
- `PlaybackModesUpdated` → `HoldOwnModesWhileSpotifyRouted`, `UpdateDeviceCache`, `PublishModesToMqtt`. Fired by listeners with the shuffle/repeat/liked they observe, and by the API after a change. The first listener remembers a device's own reports (`Modes::own()`) and stops them (returns `false`) while Spotify is routed to that device; Spotify's reports for it carry `routed: true`.
- `DeviceStateChanged` → `PublishStateToMqtt`, `SyncNowPlayingDataWithState` (clears the retained `/data` on standby/unreachable, republishes the cached track when a device comes back). Fired by `DeviceCache::updateState()` only when the cached state actually changes.

Listeners are registered only here: event auto-discovery is disabled in `bootstrap/app.php`, because it registered every listener twice.

Device listeners (`app/Integrations/*/Services/DeviceListener.php`, each a `DeviceListenerInterface` registered per driver under `listeners` in `config/devices.php`) poll devices and fire these events. `php artisan device:listen` boots once and forks one child per device (restart with backoff, rescan for added/removed devices); `device:listen-single {id}` runs one. See `docs/architecture/device-discovery.md`. The queue must be running for `StorePlaybackHistory` and `ProcessArtwork` to process.

### Integration Driver Pattern

`config/devices.php` maps device brands/models to driver classes. The file also holds `discoverers`, `listeners` and `multiroom_meta_keys`, which are no brands: read the models through `App\Domain\Device\DeviceModels` (`all()`, `find()`, `findByProduct()`), never by iterating `config('devices')`. The `Device` Eloquent model (`app/Models/Device.php`) dynamically instantiates the correct driver via the service container:

```php
app()->make($this->device_driver, ['device' => $this]);
```

All drivers implement interfaces from `app/Integrations/Contracts/`:
- `MusicPlayerDriverInterface` – current playback state
- `MediaControlsInterface` – play/pause/next/previous/stop
- `VolumeControlInterface` – volume and mute
- `SourcesInterface` / `SourceActivationInterface` – list and activate sources (ASE only)
- `MultiRoomInterface` – join/leave multiroom sessions (ASE + Sonos)
- `LibraryPlaybackInterface` – play a local DLNA track (ASE + Sonos); the `library_playback` capability also covers Spotify playback of library tracks (see Library above)
- `SeekInterface` – jump within the current track (Sonos, Spotify, Mozart)
- `QueueInterface` – list up-next tracks (Sonos, Spotify)
- `ShuffleInterface` / `RepeatInterface` – set shuffle and repeat (Sonos, Spotify, Mozart)
- `LikeInterface` – like/save the playing track (Spotify)
- `NetworkSettingsInterface` / `WirelessSpeakersInterface` – network status, interface, wired address, Wi-Fi join / WiSA scan (ASE; `HardwareFeatures` limits WiSA to models with `wisa => true`)
- `WiredSpeakersInterface` – speaker type per wired output (ASE; `HardwareFeatures` limits it to models with `speaker => 'external'`)
- `BatteryInterface` – `getBattery(): ?BatteryStatus`, null when the model has no battery (Mozart, Sonos). The listeners, not the driver, feed the cache the UI and API read.
- `SoundAdjustmentInterface` / `BluetoothInterface` / `DeviceInfoInterface` – bass/treble/loudness, Bluetooth pairing mode and paired devices, device name and firmware (ASE; Mozart and Sonos for sound adjustment and device info, Mozart lists Bluetooth; value objects in `app/Domain/Device/Settings/`; reads and writes use `HttpConnector::getStrict/putStrict` so an unreachable or rejecting device is never read as "empty")
- `SleepTimerInterface` / `DigitsInterface` – sleep timer minutes (ASE; `SleepTimer` value object, V1 read-only) and number key presses (ASE `VideoPlayerDriver` only, `POST BeoZone/Zone/Digits {digits: 0-9}`)
- `PowerInterface` – wake / standby (ASE, Mozart; Mozart can't be woken and throws `UnsupportedOperationException`, answered with 422)

`App\Domain\Device\Capabilities::forDriver()` maps these contracts to the API capability strings from the driver class name, without instantiating the driver. What a device reports is `App\Domain\Device\DeviceCapabilities::for()`: that, plus Spotify's playback capabilities while Spotify is routed to it, plus `library_playback` when Spotify can play the library on it.

### Integration Drivers

**Bang & Olufsen ASE** (`app/Integrations/BangOlufsen/Ase/`)
- `MusicPlayerDriver` — all audio capabilities
- `VideoPlayerDriver` — HDMI/video plus library playback
- Communicates via REST to `{ip}:8080/BeoZone/Zone/…`
- Capabilities: all interfaces including source activation, multiroom (JID-based), library playback, power (`BeoDevice/powerManagement/standby`, `powerState` `on` / `standby`)

**Sonos** (`app/Integrations/Sonos/`)
- Communicates via UPnP SOAP to device IP
- Library: `duncan3dc/sonos` v3
- Capabilities: media controls, volume, radio, multiroom, library playback, seek, queue, shuffle, repeat, sources (TV / line-in on soundbars and line-in speakers, by model)

**Generic UPnP MediaRenderer** (`app/Integrations/Upnp/`)
- Fallback for audio renderers no dedicated driver claims (`DedicatedDrivers`); AVTransport/RenderingControl over SOAP, queue, shuffle, repeat and up next kept by the server (`Common\DeviceQueue`), seek/volume per what the SCPDs list. No multiroom, sources, power or radio. See `docs/architecture/upnp-renderer.md`

**Philips Android TV** (`app/Integrations/Philips/`)
- `VideoPlayerDriver` extends the UPnP driver for a TV paired with `php artisan philips:pair {id}`: standby (no wake), apps as sources, the app in front as now playing. Opt-in; an unpaired Philips TV stays generic UPnP. See `docs/architecture/philips-tv.md`

**WiiM** (`app/Integrations/Wiim/`)
- `MusicPlayerDriver` extends the UPnP driver (server queue over UPnP) and adds the LinkPlay HTTP API: volume/seek, transport and loop mode for the WiiM's own playback, inputs and presets as sources, info, standby, battery, EQ presets (methods only). `WiimDiscovery`, polling listener. Built from the vendor document, unverified. See `docs/architecture/wiim.md`

**Chromecast** (`app/Integrations/Cast/`)
- `MusicPlayerDriver` (model `Google` / `Chromecast`, `standalone => false`): transport and seek over the Cast media namespace, `Services\DeviceListener` polls what is casting (the idle backdrop is not a session). A dongle is meant to be the sub device of the TV it is plugged into; added by hand, unverified. See `docs/architecture/sub-devices.md`

**Spotify** (`app/Integrations/Spotify/`)
- Virtual device — polls Spotify Web API every 3 seconds
- Capabilities: media controls, seek, queue, shuffle, repeat, like (cloud-controlled). While routed to a mapped speaker, its modes are reported for, and its playback commands accepted on, that speaker (see [Spotify routing](#spotify-routing))
- Can route playback to a mapped local device via `spotify_connect_name` device meta key

### Domain vs. Model Layer

- `app/Domain/` – Non-Eloquent value objects used in events and cache: `NowPlaying`, `TrackData`, `ArtistData`, `AlbumData`, `Radio`, `Source`; plus domain services such as `Domain/Library` (`LibraryPlayback`, `LibraryIdentity`, `Normalizer`, `LibraryMerger`)
- `app/Models/` – Eloquent models for persistence: `Device`, `DeviceSource`, `DeviceMeta`, `DlnaServer`, `MultiroomPreset`, `Play`, `Client`, media models (`Track`, `Album`, `Artist`, `Metadata`)

Domain objects represent live state; Eloquent models represent stored history.

### Device State Caching

`app/Domain/Device/DeviceCache.php` manages Redis keys:

| Key | Value | TTL |
|-----|-------|-----|
| `device:{id}:state` | `State` enum string | 3600s |
| `device:{id}:now_playing` | Serialized `NowPlaying` object | 3600s |
| `device:{id}:last_seen` | Timestamp | 3600s |
| `spotify_routed_to` | device ID integer | 30s |
| `listener_running_{id}` | boolean flag | 10s |
| `device:{id}:modes` | last known shuffle/repeat/liked (`PlaybackModes` array) | 3600s |
| `device:{id}:own_modes` | what the device's own listener last reported, restored after Spotify routing | 3600s |
| `mqtt_published_volume_{id}` | last `/volume` payload published to MQTT (dedupe) | 3600s |
| `mqtt_published_data_{id}` | md5 of the last `/data` payload published (dedupe; forgotten when cleared) | 3600s |
| `device:{id}:battery` | last reported `BatteryStatus` array | 3600s |
| `mqtt_published_battery_{id}` | last `/battery` payload published to MQTT (dedupe) | 3600s |
| `mqtt_published_modes_{id}` | last `/modes` payload published to MQTT (dedupe) | 3600s |
| `health:scheduler` / `health:queue` | ISO timestamp heartbeats for `/settings/health` | 86400s |

`State` enum values: `playing` | `standby` | `paused` | `unreachable`

### Artwork Caching & Processing

When a new track starts playing, `DispatchArtworkProcessing` dispatches the `ProcessArtwork` queued job. The job:
1. Downloads the original image URL from the music service
2. Resizes to square JPEG proxies (`SQUARE_SIZES`: 512, 320, 120 → `{size}.jpg`) and renders full-screen client backgrounds (`ArtworkBackgrounds::SIZES`: `1024x600` → `proxy_bg` / `bg_1024x600.jpg`, `320x480` → `proxy_bg_320x480` / `bg_320x480.jpg`, `480x480` → `proxy_bg_480x480` / `bg_480x480.jpg`; the cover scaled to fill (cropped to the screen's aspect), shrunk to 1/32 and scaled back up with blur passes, then overlaid with 60% black), stored under `storage/app/public/artwork/{md5(url)}/`. Add a background size by adding it to `ArtworkBackgrounds::SIZES` and its key `proxy_bg_{w}x{h}` to `ArtworkCache::REQUIRED_KEYS` and `LibraryArtwork::CLIENT_FILES`; `proxy_bg` stays the 1024×600 one for existing clients
3. Extracts 5 dominant colors via ColorThief
4. Stores proxy URLs + hex colors in Redis (`artwork:{md5}`, 30-day TTL) via `ArtworkCache`
5. Updates any matching `albums.colors` column in the database

All JPEGs are encoded baseline (`JpegEncoder(progressive: false)`; Intervention's GD encoder calls `imageinterlace(false)`), regardless of the source image, because the ESP32's TJpgDec decoder can't read progressive JPEGs.

`app/Domain/Artwork/ArtworkCache.php` provides static helpers for reading/writing the cache. `ArtworkCache::has()` is true only for **complete** entries (all `REQUIRED_KEYS`): an entry cached before a size was added is still served by `get()`, but is regenerated on next play, and `php artisan artwork:backfill` (scheduled daily) re-queues outdated entries for album covers as well as covers without colors. Artwork is absent on first play of a new URL (async), present on all subsequent plays.

**Recently played albums** (`app/Domain/Artwork/LibraryArtwork.php`): `albumsByRecency()` orders library albums by their last play in `plays` (newest first), then never-played albums by id; `recentCoverUrls()` gives the covers of the last `config('artwork.recent_albums')` (`ARTWORK_RECENT_ALBUMS`, default 500) distinct albums played. `php artisan artwork:prerender` (scheduled daily) queues `ProcessArtwork` for those whose entry isn't complete (`ArtworkCache::has()`), at most `artwork.prerender_max_jobs` (`ARTWORK_PRERENDER_MAX_JOBS`, default 100) per run, newest first, and doesn't re-queue a cover it queued in the last 12 hours — so a big backlog is worked off over several days without flooding the queue.

**SD card export:** `php artisan artwork:export-sd [--size=1024x600|320x480|480x480]` or `/settings/clients` (size selector) builds `storage/app/private/exports/remoment-sd-artwork-{size}.zip` (queued `BuildSdCardExport`, one zip per size): `remoment/covers/{hash}.jpg` (320.jpg) and `remoment/backgrounds/{hash}.jpg` (`bg_{size}.jpg`, the client's single backgrounds folder) byte copies for the recent albums and the source logos, plus a README.txt; extract at the SD card root. See `docs/architecture/sd-card-export.md`.

**Logos for playback without an image** (`app/Domain/Artwork/`): `NowPlayingArtwork` picks the image for a `NowPlaying` (cover → radio station `image_url` from `/radio` → generated logo) and adds `kind`; it is used by both `PublishNowPlayingToMqtt` and `DeviceDetailResource`. `SourceLogo` maps the source's `sourceType`/`name`/`connector`/`category` to a logo key (`spotify`, `radio`, `line_in`, `bluetooth`, `tv`, `cast`, `cd`, default `music`) and addresses it by a pseudo URL `remoment:logo/v{VERSION}/{key}`, so its hash is the same on every server. `LogoRenderer` draws the glyph (off-white line icon on a #181818 square, GD, 4× supersampled, deterministic); `ProcessArtwork` renders it instead of downloading. Missing logo entries are rendered synchronously (~0.2s, a handful of logos). Bump `SourceLogo::VERSION` when a glyph changes so clients' hash-keyed caches pick it up.

**Cover health** (`App\Domain\Artwork\CoverFacts`): `ProcessArtwork` records the original's `cover_size` and `cover_sha1` as album metadata and `FileIndexer` the embedded picture's `embedded_cover_sha1`; the library health report `covers` reads them (thresholds `artwork.min_cover_px`, `artwork.square_tolerance`; profile feature `cover_health`, Standard and Full). See `docs/architecture/library-health.md`.

**Loudness-normalised playback** (opt-in Setting `playback_loudness`, `App\Domain\Storage\PlaybackLoudness`, admin toggle on `/settings/storage` › Library and `GET|PUT /api/admin/storage/playback-loudness`): a gain in the proxies the server converts for a limited renderer (`Upnp\StreamProfile`), never for direct DLNA playback, Spotify or radio, never past the stored true peak; no file tags written, no device volume moved. See `docs/architecture/audio-analysis.md`.

**Playlist covers** (`app/Domain/Artwork/PlaylistArtwork.php`): a 2×2 mosaic of the covers of the 4 albums with the most tracks in the playlist (ties by playlist order; distinct cover URLs), drawn by `PlaylistCompositeRenderer` (1024² PNG from each cover's processed 512 proxy, else downloaded directly) and processed by `ProcessArtwork` like any cover under the pseudo URL `remoment:playlist/v{VERSION}/{md5 of the 4 cover URLs}`. The URL is a hash, so the job is dispatched with its 4 source URLs (`new ProcessArtwork($url, $sources)`; a composite without matching sources is skipped). 1–3 distinct covers → the first album's cover; none → the playlist's own image. The mosaic is used even when the playlist has its own image, for a consistent look. `artwork:prerender` queues missing playlist covers first (they share `--max-jobs`), `artwork:backfill` queues missing/outdated ones; a changed playlist gets a new URL, so a new composite. Bump `PlaylistArtwork::VERSION` when the layout changes.

**Web UI never shows an original image URL** (DLNA, Spotify, radio): `LibraryItemArtwork::proxy($url, 120|320)` gives our processed proxy as a root-relative path, or `null` until it is processed (queued meanwhile, a failing queue never breaks the page); `<x-artwork-thumb>` applies it to any raw `src`, so views show a placeholder rather than the original. `Play::artUrl($size)` does the same for history. The REST API's `images` arrays stay the originals; clients use `artwork`.

Run `php artisan storage:link` once on new environments to create the `public/storage` symlink.

### Playback History

`StorePlaybackHistory` (queued) persists each play to the `plays` table via the `Play` Eloquent model, finding the track (and its artist/album) through `LibraryIdentity`, so a play counts for the scanned or imported track. `ClosePlaybackHistory` sets `ended_at` when playback stops. Library creation from plays is behind the setting `library_add_played_tracks` (default off, `/settings/storage` › Library, `LibrarySettings::addPlayedTracks()`): off, only a track already in the library is matched (`LibraryIdentity::lookupTrack`); any other is logged with `track_id` null and its `track_name`, `artist_name`, `album_name`, `image_url` and `duration` on the play (so history, the artwork proxy and Last.fm still work), and nothing is added to the library. Plays can be browsed at `/history`.

### DLNA Library

DLNA servers are discovered on the network and their tracks imported into the shared media library.

**Models:**
- `DlnaServer` — `friendly_name`, `ip`, `port`, `control_url`, `last_scanned_at`
- Tracks are stored as `Track` Eloquent models with `source = 'dlna'` and an `external_id` of `{server_id}:{dlna_object_id}`
- The stream URL is stored as a `Metadata` row: `key = 'dlna_url'`, `source = 'dlna:{server_id}'` (one per server), `type = 'url'`

**Scanner:** `DlnaLibraryScanner` recursively browses the DLNA content tree via `DlnaContentDirectoryClient` (SOAP/UPnP), finding or creating `Artist`, `Album` and `Track` records through `LibraryIdentity` (a track already imported from Spotify or recorded from a play is reused and gets the `dlna_url`) and `Metadata` records. Triggered via the Settings UI or `php artisan library:scan [--server=ip]`.

### Streaming extends the library

**Local means the own files and nothing else; DLNA is never local** (it is a streaming library, though it counts as owned for the Owned badge and closing wishes: it is your own music). The library is the local tracks. A streaming service (Spotify; Deezer and Tidal later) **extends** it live and nothing of it is imported: no Spotify library, no sync of saved tracks or playlists, no "add to library". What a service gives: search and **play** (`/api/library/external/...`, `play-external`), browse (an artist with all its albums and popular tracks, an album, a playlist), and **want** (a streaming track goes on the wishlist as a `wanted_tracks` row, source `streaming`). A streaming track is never favorited (favorites are local). Web: the artist and album pages have a Spotify side that shows what Spotify has (`/artists/{id}?source=spotify`, `Livewire\ExternalArtist`/`AlbumMoreOnSpotify`), `/external/spotify/{artists|albums|playlists}/{id}`, and `/playlists` has a Local | Streaming filter (the account's playlists read live). `LibrarySources::STREAMING_SERVICES` names them, `config/availability.php` lists the providers; `docs/api/availability.md`.

### Leading source

`App\Domain\Library\LeadingSource`: the own files (library `files`, kind `local`) lead when indexed, else DLNA when it has tracks, else the own files (DLNA is kind `streaming`; scope `local` = own files, `streaming` = DLNA; **Spotify is not a library**: it extends the library live, see "Streaming extends the library" below; setting at `/settings/storage` › Library). Web pages and `/api/library/*` default to that source's scope; `?scope=local|streaming|all` overrides it, nothing is deleted. See `docs/architecture/library-identity.md`.

### Library Identity

One record per artist, album and track across all sources (DLNA scan, own files, plays); see `docs/architecture/library-identity.md`.

- `App\Domain\Library\Normalizer` — comparison keys stored in `name_key` on `artists`/`albums`/`tracks` (set by a `saving` hook): case, diacritics, punctuation, `&`/"and", whitespace; artists also a leading "The " and "feat." credits; albums/tracks trailing pure edition markers ("(Remastered 2011)", "- Deluxe Edition"; not "(Live)", "(Acoustic)", "(Mono)", "(Remix)"); tracks a "(feat. …)" credit. Bump `Normalizer::VERSION` when a rule changes.
- `App\Domain\Library\LibraryIdentity` — find-or-create used by every creation path: artist by key; album by artist + key; track by external id (own, or kept as `external_id` metadata), then album + key (album-less: artist + key, preferring an album track), durations within 5 s. A second source's external id is kept as `external_id` metadata, so a track can have both a DLNA URL and a Spotify URI.
- `php artisan library:merge-duplicates [--dry-run] [--force]` (`LibraryMerger`) merges existing duplicates into the oldest record, one transaction per group: re-points tracks/albums/plays/playlist entries/metadata, keeps the earliest `favorited_at` and the processed cover, deletes the rest. Idempotent. Scheduled weekly (Sunday 04:00, `--force`); each deleted id is written to `id_aliases` in the same transaction and keeps resolving to the survivor (`IdAliases`, `canonical_id` on detail responses; docs/architecture/library-identity.md).

**Playback:** `LibraryPlaybackInterface::playLibraryTrack(Track $track)` fetches the DLNA URL from metadata and streams it to the device. On Sonos this uses a `SonosTrack` wrapper; on ASE it calls `playDlnaTrack()`.

### Spotify Connect → Local Device Mapping

The Spotify virtual device listener polls `GET /v1/me/player` every 3 seconds. When playback is active:

1. Checks the playing Spotify Connect device name against `device_meta` rows with `key = 'spotify_connect_name'`
2. If a match exists, routes the `NowPlayingUpdated` event to the matched local device ID
3. The Spotify virtual device is kept in `Standby` state; the mapped local device shows the playback, and its modes
4. If the Spotify Connect speaker changes mid-playback, the previous effective device receives `NowPlayingEnded` first and gets its own modes back
5. `spotify_routed_to` (30 s TTL, refreshed every poll) holds the speaker; it is kept while paused on the speaker unless the speaker itself reports `playing`

`SpotifyRouting` turns that into one device for commands, capabilities and device lists; see [Spotify routing](#spotify-routing).

Mappings are managed in the Settings UI at `/settings/spotify-connect`. Stored as:
```
device_meta: key = 'spotify_connect_name', value = '<Spotify device name>'
```

### Multiroom / Device Joining

`MultiRoomInterface` is implemented by ASE and Sonos drivers.

**ASE implementation** (`app/Integrations/BangOlufsen/Ase/Connectors/MultiRoomControls.php`):
- `getMultiRoomId()` – fetches the B&O JID (`activeSources.primaryJid`) from the device; cached in Redis and stored in `device_meta` as key `ase_jid`
- `getJoinablePeerIds()` – returns JIDs from `primaryExperience.listenerList._capabilities.value["listener.jid"]` (literal dot in key — use direct array access, not `data_get`)
- `joinSession(Device $host)` – the **host** adds this device (`SessionHostInterface::addListener()`): `POST {host ip}:8080/BeoZone/Zone/ActiveSources/primaryExperience` with this device's JID as `listener.jid`. (POSTing the host's JID to our own endpoint makes us the host of an empty experience and stops the playback we meant to join — confirmed on hardware.) A host without `SessionHostInterface` makes the join throw `UnsupportedOperationException`. The Mozart host implements it with `POST /api/v1/beolink/expand/{jid}` (from the OpenAPI spec, unverified on hardware).
- `leaveSession()` – `DELETE {ip}:8080/BeoZone/Zone/ActiveSources/primaryExperience`

**Sonos implementation** (`app/Integrations/Sonos/Connectors/MultiRoomControls.php`):
- `joinSession(Device $host)` – constructs per-IP `Network` for both host and self, calls `SetAVTransportURI` SOAP action at the Speaker level (avoids coordinator requirement)
- `leaveSession()` – calls `BecomeCoordinatorOfStandaloneGroup` SOAP action on the speaker
- `getJoinablePeerIds()` returns `[]`; UI falls back to showing all Sonos devices

**JID-to-Device lookup:** `App\Domain\Device\MultiRoomPeers::devices()` queries `device_meta` where `key` is one of `config('devices.multiroom_meta_keys')` (`ase_jid`, `mozart_jid`, `sonos_uuid`) and `value IN ($peerIds)`, so ASE and Mozart (whose JIDs are mostly interchangeable) list each other. Add a new platform's key to that config.

Populate JIDs for all B&O devices with: `php artisan device:sync-sources`

### Multiroom Presets

`MultiroomPreset` model stores named groupings of device IDs (`device_ids` JSON column). Activating a preset calls `joinSession($host)` on all member devices, with the first device as host. Managed at `/multiroom` via the `MultiroomPresets` Livewire component.

### Client Device Management

Physical and software controllers (ESP8266, ESP32, Raspberry Pi, Squeezebox Touch, etc.) self-register and are approved by an admin. The server tracks firmware metadata and controls which media players each client can see.

Two client types: `single` (one assigned device) and `multi` (explicit list, or all devices when none assigned).

Key files:
- Model: `app/Models/Client.php` + `client_device` pivot
- API controller: `app/Http/Controllers/Api/ClientController.php`
- Admin UI: `app/Livewire/ClientManager.php`
- Settings page: `resources/views/settings/clients.blade.php` at `/settings/clients`

See `docs/architecture/client-devices.md` for the full DB schema, device resolution logic, and component internals.
See `docs/api/client-devices.md` for the firmware integration guide and full endpoint reference.

### Nodes (workstream 18, 1.1)

A **node** is a client with `clients.kind = 'node'` (headless agent for a Pi: CD ripper, line-in later), registered through the same flow with `kind`, `roles` and `capabilities`; the admin switches declared roles on (`enabled_roles`, `Client::activeRoles()`) in `/settings/clients`, where nodes carry a badge. Built (N0, N1, and N3 line-in against fakes): the server side (columns, register/heartbeat fields, UI, `App\Domain\Node\NodeRoles`; `node_jobs`, `App\Domain\Node\NodeJobs`, node routes `/api/nodes/job` and `/api/nodes/jobs/{id}/accept|progress|result` with the node's own Bearer token, admin job routes `/api/admin/nodes/{id}/jobs`, the `node.offline` alert, MQTT nudge `remoment/node/{id}/cmd/job`) and the Go agent in `node/` (`make test`, `make build`; job types `ping`, `line_in.start`, `line_in.stop`). **Line-in** (18.2, `docs/architecture/line-in.md`): a node with the `line-in` role runs `nice ffmpeg` (ALSA device from its own config, never a job param) pushing MP3 to Icecast `linein-{node id}.mp3`; `App\Domain\LineIn\LineIns` offers it as a `stream_url` source: `GET /api/line-ins`, `POST /api/devices/{id}/line-in {node_id?}` (`listLineIns`, `playLineIn`, MCP `line_in`, Line-in card on the device page), 2 to 5 s latency (not for TV), one encode per line-in, idle stop by the `line-in-sweep` task (`LINE_IN_IDLE_SECONDS`), Icecast port and source password handed to the node only when it fetches the job; unverified on hardware. Not built: the ripper role, the upload for a node, the other `remoment/node/{id}/…` topics. Plan and decisions (Go binary first, REST authoritative with MQTT only as a doorbell on the open broker, fixed job types, separate from the Manager and the Ripper desktop app): `docs/architecture/node-agent.md`.

### Source Management

`DeviceSource` rows represent sources synced from the device. Two UI-controlled fields:
- `hidden` (bool) — exclude from source list display
- `sort_order` (unsigned smallint) — custom display order

Managed via the `DeviceSourceManager` Livewire component on the device detail page. Preferences survive source refreshes because records are keyed by `source_id`.

### Local Packages

Mozart-platform support ships as two git-submodule Composer packages under `packages/`, wired via `path` repositories in the root `composer.json` (see `docs/architecture/plugin-architecture.md`):
- `packages/djneo92nl/beo-mozart-php` — pure PHP Mozart REST + WebSocket client, no Laravel dependency, tests standalone via its own `composer test`. Submodule: `github.com/djneo92nl/beo-mozart-php`.
- `packages/remoment/mozart-driver` — Laravel driver package implementing the Mozart `MusicPlayerDriver` on top of it. Not yet split into its own repo/submodule.

See `docs/architecture/device-drivers.md` for the Mozart driver's contracts and capabilities.

---
