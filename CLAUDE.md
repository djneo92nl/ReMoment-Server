# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ReMoment Server is a Laravel 12 application that acts as a universal controller and abstraction layer for networked audio/video devices (Bang & Olufsen ASE, Sonos, Spotify). It normalizes control interfaces across brands, captures playback history, publishes events to MQTT for IoT integration, and exposes a REST API for client devices.

## Auth Model

There is exactly **one shared admin login** (seeded by `DatabaseSeeder` as `admin@admin.com`) — there is no self-registration route, no multiple accounts, and no per-user profiles or roles. This one login gates only admin setup/config actions: `/settings/*`, the Spotify/Last.fm OAuth connect flows, and client device approval. Every other page — `/devices`, `/receiver`, `/history`, `/library`, `/stats`, playback controls, etc. — is intentionally guest-accessible with no login required, matching the REST API (also unauthenticated). Apps that can't use the session cookie sign in with that same password at `POST /api/admin/login` and get a bearer token (one hashed row per app install in `admin_tokens`, revocable under Settings › Users, all revoked when the password changes; see `docs/api/admin-auth.md`). Admin API routes go inside `Route::middleware('admin.token')`. A token is not an account. **Never add user registration, multiple accounts, profiles, or role-based access** — this is a deliberate, permanent constraint, not an oversight to "fix."

## Documentation

Detailed documentation lives in `docs/`. CLAUDE.md holds enough context to understand the project; the docs hold enough to implement or integrate without reading the source.

```
docs/
  api/
    client-devices.md       Client device registration flow, all endpoints, firmware guide
    library.md              Library browse (artists, albums, playlists), favorites, play album/artist/playlist on a device (DLNA or Spotify), playlist artwork
    source-controls.md      Per-source controls (change disc, FM presets) from control profiles: GET/POST /api/devices/{id}/controls, resolution, transports
    server-info.md          GET /api/info bootstrap endpoint (API/MQTT/artwork addresses for clients)
    admin-auth.md           Token login of the single admin for apps: POST /api/admin/login, bearer tokens, revocation, what it gates
    openapi.yaml            OpenAPI 3 contract for native clients (info, devices, playback, radio, multiroom, library, clients); update it when an endpoint's shape changes
    device-settings.md      Sound adjustment (bass/treble/loudness), Bluetooth pairing, device name: endpoints, shapes, per-platform support
  architecture/
    client-devices.md       DB schema, model, controller, admin UI internals
    device-drivers.md       Driver contracts, existing drivers, guide for adding a new brand
    device-discovery.md     Discovery commands, listener startup, device_meta keys
    ase-api-map.md          What the B&O ASE REST API offers beyond the driver (Bluetooth, network, WiSA, sound), per model; the ase:map command
    proxmox-deployment.md   Ansible: LXC container on an existing Proxmox host, MiniDLNA server
    discovery-for-clients.md mDNS advertisement (_remoment._tcp via host Avahi) so clients find the server
    metadata-enrichment.md  Per-source enrichment jobs (MusicBrainz track/artist/album, Wikipedia, Cover Art Archive, lyrics, Spotify, Last.fm, TheAudioDB, Discogs), DLNA tags, canonical genres: markers, retries, rate limits, library:enrich
    lastfm.md                Scrobbling, now-playing, auth flow, artist enrichment, backfill
    library-identity.md      One record per artist/album/track across sources: name keys, find-or-create, library:merge-duplicates
    live-updates.md          MQTT-over-WebSocket push to Livewire + /receiver, topics, fallback polling
    sd-card-export.md        Admin zip of recent covers/backgrounds + logos in the touch client's SD layout
    plugin-architecture.md  Design doc: extracting drivers into composable packages
    setup-wizard.md          First-time setup wizard: steps, Setting flags, auto-redirect middleware
  frontend/
    receiver.md             /receiver view: JS globals, DOM structure, browser targets
    player-bar.md           Sticky bottom player: pinned device cookie, <x-play-button> default play target
```

When adding a significant feature, add a matching doc in the relevant subfolder (`api/` for new REST endpoints, `architecture/` for server-side systems, `frontend/` for complex UI pages).

## Common Commands

```bash
# Initial setup
composer setup

# Start full dev environment (Laravel, queue, Vite, log viewer)
composer dev

# Run tests
composer test

# Run a single test file or method
php artisan test --filter=TestClassName
php artisan test tests/Path/To/TestFile.php

# Code style
./vendor/bin/pint

# Discover devices of every registered brand (SSDP/mDNS/Sonos topology) and store them
php artisan device:discover [--brand=sonos|ase|mozart] [--host=192.168.1.9]

# Sync B&O source/JID data for all ASE devices
php artisan device:sync-sources

# Map a B&O ASE device's (or NetworkLink/MasterLink converter's) REST API — GET-only crawl; --compare diffs two devices
php artisan ase:map {device id|ip} [--seed=BeoZone/Zone/Video] [--compare=storage/app/ase-maps/other.json]

# Scan DLNA servers' libraries (triggered via UI or manually)
php artisan library:scan [--server=192.168.1.20] [--root=Muziek]   # --root: start in a folder (title, object id or Muziek/Albums) so a server with films and series isn't crawled first

# Queue MusicBrainz/lyrics/Spotify/Last.fm/TheAudioDB/Discogs enrichment for tracks, artists and albums still missing a source, plus Spotify photos for artists without one (scheduled daily; artists with a Spotify id are fetched 50 per request, others looked up by exact name). --only=metadata|artist-images runs one part
php artisan library:enrich [--only=metadata --only=artist-images] [--limit=200] [--dry-run]

# Rebuild canonical genres from stored genre metadata (after changing GenreNormalizer)
php artisan library:sync-genres

# Merge library duplicates across sources (DLNA / Spotify / plays) — dry run first
php artisan library:merge-duplicates --dry-run
php artisan library:merge-duplicates [--force]

# Queue missing artwork for playlist covers and the last N played albums (scheduled daily)
php artisan artwork:prerender [--limit=500] [--max-jobs=100]

# Build the SD card artwork zip for the touch client (also on /settings/clients)
php artisan artwork:export-sd [--size=1024x600|320x480|480x480] [--limit=500] [--queue]
```

## REST API Reference

Base URL: `/api` — no authentication required.

### Server Info

**`GET /api/info`** — bootstrap info for client devices: `{ name, version, api_version, base_url, api_base_url, artwork_base_url, mqtt: { host, port, ws_port, ws_url, topic_prefix }, placeholder }`. `placeholder` is a full artwork object (`kind: "source"`, the music-note logo, rendered on the spot if missing) for clients to show whenever there is no artwork, e.g. while a cover is first processed. Addresses are as the client reached the server; `mqtt.host` is `MQTT_PUBLIC_HOST` or the request host (never the Docker-internal `MQTT_HOST`); `mqtt.ws_port`/`ws_url` are the WebSocket listener (`MQTT_PUBLIC_WS_PORT`, default 9001; `MQTT_WS_URL` overrides the URL). See `docs/api/server-info.md`.

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

`capabilities` values: `media_controls` | `volume_control` | `radio_control` | `source_control` | `source_activation` | `multi_room` | `library_playback` | `seek` | `queue` | `queue_jump` | `shuffle` | `repeat` | `like` | `power` | `sound_adjustment` | `bluetooth` | `device_info` | `battery` | `network_settings` | `wireless_speakers` | `wired_speakers` | `sleep_timer` | `digits` | `source_controls`

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

`now_playing` is `null` when the device is in standby or unreachable. `artwork.kind` says what the image is: `album` (track/album cover), `radio` (the station's image from the stream or from its `image_url` in `/radio`, else a generated radio logo) or `source` (a generated logo for the source — Spotify, line-in, Bluetooth, TV, AirPlay/Cast, CD, or a music note — when nothing playing has an image). Logos have the same keys and go through the same pipeline as covers, so clients need no special case. A real image's `artwork` is absent on the first play of a new URL (processed asynchronously), present on all subsequent plays; logos are always present. Artwork URLs are prefixed with `APP_URL` (root-relative `/storage/…` when it is empty); clients should take the path from `/storage/` on and prefix `artwork_base_url` from `GET /api/info`. `proxy_512`/`proxy_320`/`proxy_120` are square covers; `proxy_bg` is a blurred, darkened 1024×600 background for the 7" landscape client and `proxy_bg_320x480` the same for the 3.5" portrait client and `proxy_bg_480x480` for a square 480×480 screen (extra background sizes are always `proxy_bg_{w}x{h}`; a client picks `proxy_bg_{width}x{height}` for its own screen (e.g. `proxy_bg_320x480` on the 3.5" portrait client) and falls back to `proxy_bg` (1024×600) when that key is absent); all are **baseline** JPEGs (ESP32 TJpgDec can't decode progressive). `safe_colors` are `colors` lightened to ≥65% lightness for text/accents on dark backgrounds. An entry cached before a key existed may lack it until regenerated. `radio` and `source` keys are absent when not applicable — use whichever is present to identify playback type. `modes` is always present: `shuffle` (bool), `repeat` (`off` | `all` | `one`) and `liked` (bool), each `null` when unknown or unsupported; the same object is pushed on MQTT `/modes`.

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

### Volume

```
GET  /api/devices/{id}/volume          → { "volume": 45 }
PUT  /api/devices/{id}/volume          body: { "volume": 45 }   → { "volume": 45 }
```

Volume is an integer 0–100.

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

The web page `/devices/{id}/settings` (admin login, `App\Livewire\DeviceSettings`) shows one card per capability the device has.

```
GET|PUT  /api/devices/{id}/sleep-timer   → { value, min, max, step, writable }   PUT { minutes } (0 cancels; 0–60)   (capability `sleep_timer`; ASE — the BeoPlay V1 lists it but is `writable: false`)
POST     /api/devices/{id}/digits   body { digits: "105" } → { status, digits }   number keys for the active TV / set-top box source, sent in order (capability `digits`; ASE VideoPlayerDriver = BeoPlay V1)
GET      /api/devices/{id}/network   → active interface, wired/wireless status and addresses, known Wi-Fi networks (never a passphrase)   (capability `network_settings`; ASE)
PUT      /api/devices/{id}/network/interface | /network/wired | /network/wifi   → { status: "ok" } — not read back, the device may change address
GET      /api/devices/{id}/wireless-speakers   POST …/wireless-speakers/scan { action: start|stop }   (capability `wireless_speakers`; WiSA: **only the BeoSound Moment**, via `wisa => true` in config/devices.php / `HardwareFeatures`)
GET      /api/devices/{id}/wired-speakers   PUT …/wired-speakers/{pl_1|pl_2} { type?, sound? }   (capability `wired_speakers`; which speaker type is on each wired output: **only models with external speakers**, Essence and Moment, via `speaker => 'external'` in config/devices.php / `HardwareFeatures`)
```

### Source controls

`source_controls` is reported while the playing source has controls a transport can deliver (CD changer, tape, FM presets); full description in `docs/api/source-controls.md`.

```
GET  /api/devices/{id}/controls            → { "profile": "cd", "label": "CD", "controls": [ { id, label, icon, type, group }, … ] }
POST /api/devices/{id}/controls/{control}  → { "status": "ok", "control": "next_disc" }
```

Profiles are in `config/control-profiles.php`; `App\Domain\Control\SourceControls` resolves the profile from the active source and sends commands through a `ControlTransport`. `422` for an unknown control or none for the source, `503` unreachable, `502` the transport failed.

### Up Next (queue)

Requires `queue` (Sonos, Spotify).

```
GET  /api/devices/{id}/queue?limit=20  → { "up_next": [ QueueItem, … ] }
```

`limit` is 1–100 (default 20). Returns only the tracks after the current one. The list is empty when the device isn't playing from a queue (radio, line-in).

**QueueItem shape:** `{ "name": "…", "artist": "…"|null, "album": "…"|null, "image": "https://…"|null, "duration": 200|null, "uri": "…"|null, "artwork": { hash, proxy_120, proxy_320 }|null }`. `artwork` is the small library artwork object (see Library): the item's `image` is processed through the artwork pipeline (queued at most once an hour per image, never waited for), so it is `null` until processed.

Validation errors return Laravel's standard `422` `{ "message", "errors" }`. Unknown devices and routes return `404`.

### Sources

```
GET  /api/devices/{id}/sources         → { "sources": [ AvailableSource, … ] }
POST /api/devices/{id}/sources/activate  body: { "source_id": "MUSIC" }   → { "status": "ok", "source_id": "MUSIC" }
```

`GET` fetches live data from the device and refreshes the `device_sources` table; hidden/sort preferences are preserved by `source_id`.

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
  "provider_name": "Spotify"
}
```

### Radio

```
GET  /api/devices/{id}/radio                → { "stations": [ RadioStationItem, … ] }
POST /api/devices/{id}/radio/{station_id}   → { "status": "ok", "station": "Radio 1" }
```

`station_id` is the `RadioStation` model ID. The device must have `radio_control` capability and a compatible radio platform. `GET` lists exactly the stations with an identifier for the device's platform (`beoradio` for ASE/Mozart, `tunein` for Sonos), ordered by name like `/radio`; `422` without `radio_control`, `503` while unreachable (building the driver may contact the device).

**RadioStationItem shape:** `{ "id": 3, "name": "Radio 1", "genre": null, "favorite": false, "artwork": { "kind": "radio", "proxy_512": "…", … } | null }`. `genre` is always `null` for now (stations have no genre field). `favorite` is household-wide (`radio_stations.favorited_at`); `PUT /api/radio/{station_id}/favorite` with `{ "favorite": true }` → `{ "favorite": true }` sets it. The `/radio` web page lists favorites first; the API list stays by name. `artwork` is the same object as `now_playing.artwork`: the station's `image_url`, else the generated radio logo. An `image_url` that isn't processed yet gives `null` and is queued (at most once an hour per image), so the request never waits for a download.

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

Browse, favorites and playback for clients; full shapes in `docs/api/library.md`. Artwork in these responses is the small object `{ hash, proxy_120, proxy_320 }` or `null` while unprocessed (queued at most once an hour per image).

```
GET  /api/library/artists?cursor=          → { data: [ArtistItem], next_cursor }   alphabetical ("The " ignored), 50/page, only artists with albums
GET  /api/library/albums?cursor=&sort=      → { data: [AlbumItem], next_cursor }   sort name (default) | artist | year | added | plays, 60/page, only albums with tracks
GET  /api/library/artists/{id}             → { id, name, favorite, genres, details (bio, links, images, mood, Last.fm stats …), albums: [AlbumItem] }   newest year first, then name
GET  /api/library/albums/{id}?device_id=   → { id, name, artist: {id, name}, year, artwork, favorite, genres, details (label, release type, mood, rating, credits …), playable, tracks: [{ id, name, duration, playable, details }] }
GET  /api/library/genres                   → { data: [{ slug, name, artist_count, album_count }] }   canonical genres, most artists first
GET  /api/library/genres/{slug}            → { slug, name, artists: [ArtistItem], albums: [AlbumItem] }   up to 100 of each
GET  /api/library/playlists?cursor=        → { data: [PlaylistItem], next_cursor }   recently played (last_played_at) first, then by name, 50/page, only playlists with tracks
GET  /api/library/playlists/{id}?device_id= → { id, name, owner, artwork, playable, tracks: [{ id, name, artist_name, duration, playable }] }   playlist order, first 200
GET  /api/library/recent?limit=30          → { data: [AlbumItem] }   most recently played distinct albums (limit 1–100)
GET  /api/library/favorites                → { albums: [AlbumItem], artists: [ArtistItem] }   most recently favorited first
PUT  /api/library/albums/{id}/favorite     body: { "favorite": true }   → { "favorite": true }
PUT  /api/library/artists/{id}/favorite    body: { "favorite": true }   → { "favorite": true }
```

ArtistItem `{ id, name, album_count, artwork, favorite }`; AlbumItem `{ id, name, artist_name, year, artwork, favorite }`; PlaylistItem `{ id, name, track_count, owner, artwork }` (`owner` = Spotify owner or `null`; `artwork` = the generated playlist cover, see [Artwork](#artwork-caching--processing)). A playlist counts as played when started from the server (API or web), which sets `playlists.last_played_at`. Favorites are household-wide (`favorited_at` on `albums`/`artists`; the web album/artist pages toggle them too). There is one record per artist/album/track across sources (see [Library identity](#library-identity)); ids of records merged by `library:merge-duplicates` disappear, so clients get `404` (or `422` in play bodies) for a stale id and should re-read the list.

**Playing from the library**

```
POST /api/devices/{id}/library/play            body: { "track_id": 42 }                                        → { "status": "ok", "track": "Song Title" }
POST /api/devices/{id}/library/play-playlist   body: { "playlist_id": 3, "start_track_id": 12, "shuffle": false } → { "status": "ok", "playlist": "…" }
POST /api/devices/{id}/library/play-album      body: { "album_id": 12, "start_track_id": 34, "shuffle": false } → { "status": "ok", "album": "…" }
POST /api/devices/{id}/library/play-artist     body: { "artist_id": 5, "shuffle": true }                        → { "status": "ok", "artist": "…" }
```

`App\Domain\Library\LibraryPlayback` (shared with the web play buttons) tries **DLNA** first — the device's `LibraryPlaybackInterface` driver, for tracks with a `dlna_url` — then **Spotify** — when Spotify is connected and the device is the Spotify device or mapped to a Spotify Connect name, for tracks with a Spotify URI (`external_id` `spotify:track:…`): (the track's own `external_id`, or kept as `external_id` metadata with source `spotify` on a merged track): an album plays as its Spotify album context (URI looked up once, stored as album metadata `spotify_album_uri`), an artist as a list of track URIs, a Spotify playlist as its context (offset at `start_track_id`, or random when shuffling); other playlists play their tracks like an album. `start_track_id`/`shuffle` are optional on play-album and play-playlist. A device has `library_playback` when either path exists. Errors: `422` `not_playable` (play-album/artist/playlist: neither path works), `422` `no_dlna_url` (`/library/play` on a DLNA device without a stream for the track), `422` `unsupported` (`/library/play`, `/play-playlist` without `library_playback`), `503` unreachable, `502` `driver_error` (device or Spotify failed, or Spotify doesn't list the mapped speaker).

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

## MQTT Reference

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

`/data` is retained so a client subscribing later (e.g. after switching device) gets the current track at once; when the device goes to standby or becomes unreachable the server publishes an empty retained payload, which also removes the retained track from the broker, so clients must treat an empty `/data` as "nothing playing". It is republished from the cache when a device comes back, and identical payloads aren't repeated. `artwork` is absent in the MQTT payload if a real image is not yet processed; `kind` is `album` | `radio` | `source` as in the REST API. Published by the `PublishNowPlayingToMqtt`, `PublishProgressToMqtt`, `PublishStateToMqtt`, `PublishVolumeToMqtt`, `PublishBatteryToMqtt` and `PublishModesToMqtt` listeners. The server only publishes and never subscribes. Browsers subscribe over the WebSocket listener on port 9001 to drive live UI refreshes; see `docs/architecture/live-updates.md`.

---

## Architecture

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

`config/devices.php` maps device brands/models to driver classes. The `Device` Eloquent model (`app/Models/Device.php`) dynamically instantiates the correct driver via the service container:

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

**Playlist covers** (`app/Domain/Artwork/PlaylistArtwork.php`): a 2×2 mosaic of the covers of the 4 albums with the most tracks in the playlist (ties by playlist order; distinct cover URLs), drawn by `PlaylistCompositeRenderer` (1024² PNG from each cover's processed 512 proxy, else downloaded directly) and processed by `ProcessArtwork` like any cover under the pseudo URL `remoment:playlist/v{VERSION}/{md5 of the 4 cover URLs}`. The URL is a hash, so the job is dispatched with its 4 source URLs (`new ProcessArtwork($url, $sources)`; a composite without matching sources is skipped). 1–3 distinct covers → the first album's cover; none → the playlist's own image. The mosaic is used even when the playlist has its own image, for a consistent look. `artwork:prerender` queues missing playlist covers first (they share `--max-jobs`), `artwork:backfill` queues missing/outdated ones; a changed playlist gets a new URL, so a new composite. Bump `PlaylistArtwork::VERSION` when the layout changes.

**Web UI never shows an original image URL** (DLNA, Spotify, radio): `LibraryItemArtwork::proxy($url, 120|320)` gives our processed proxy as a root-relative path, or `null` until it is processed (queued meanwhile, a failing queue never breaks the page); `<x-artwork-thumb>` applies it to any raw `src`, so views show a placeholder rather than the original. `Play::artUrl($size)` does the same for history. The REST API's `images` arrays stay the originals; clients use `artwork`.

Run `php artisan storage:link` once on new environments to create the `public/storage` symlink.

### Playback History

`StorePlaybackHistory` (queued) persists each play to the `plays` table via the `Play` Eloquent model, finding the track (and its artist/album) through `LibraryIdentity`, so a play counts for the scanned or imported track. `ClosePlaybackHistory` sets `ended_at` when playback stops. Library creation from plays is behind the setting `library_add_played_tracks` (default off, `/settings/library`, `LibrarySettings::addPlayedTracks()`): off, only a track already in the library is matched (`LibraryIdentity::lookupTrack`); any other is logged with `track_id` null and its `track_name`, `artist_name`, `album_name`, `image_url` and `duration` on the play (so history, the artwork proxy and Last.fm still work), and nothing is added to the library. Plays can be browsed at `/history`.

### DLNA Library

DLNA servers are discovered on the network and their tracks imported into the shared media library.

**Models:**
- `DlnaServer` — `friendly_name`, `ip`, `port`, `control_url`, `last_scanned_at`
- Tracks are stored as `Track` Eloquent models with `source = 'dlna'` and an `external_id` of `{server_id}:{dlna_object_id}`
- The stream URL is stored as a `Metadata` row: `key = 'dlna_url'`, `source = 'dlna:{server_id}'` (one per server), `type = 'url'`

**Scanner:** `DlnaLibraryScanner` recursively browses the DLNA content tree via `DlnaContentDirectoryClient` (SOAP/UPnP), finding or creating `Artist`, `Album` and `Track` records through `LibraryIdentity` (a track already imported from Spotify or recorded from a play is reused and gets the `dlna_url`) and `Metadata` records. Triggered via the Settings UI or `php artisan library:scan [--server=ip]`.

### Leading source

`App\Domain\Library\LeadingSource`: DLNA leads the library when it has imported tracks (else Spotify; setting at `/settings/library`). Web pages and `/api/library/*` default to that source's scope; `?scope=local|streaming|all` overrides it, nothing is deleted. See `docs/architecture/library-identity.md`.

### Library Identity

One record per artist, album and track across all sources (DLNA scan, Spotify import, plays); see `docs/architecture/library-identity.md`.

- `App\Domain\Library\Normalizer` — comparison keys stored in `name_key` on `artists`/`albums`/`tracks` (set by a `saving` hook): case, diacritics, punctuation, `&`/"and", whitespace; artists also a leading "The " and "feat." credits; albums/tracks trailing pure edition markers ("(Remastered 2011)", "- Deluxe Edition"; not "(Live)", "(Acoustic)", "(Mono)", "(Remix)"); tracks a "(feat. …)" credit. Bump `Normalizer::VERSION` when a rule changes.
- `App\Domain\Library\LibraryIdentity` — find-or-create used by every creation path: artist by key; album by artist + key; track by external id (own, or kept as `external_id` metadata), then album + key (album-less: artist + key, preferring an album track), durations within 5 s. A second source's external id is kept as `external_id` metadata, so a track can have both a DLNA URL and a Spotify URI.
- `php artisan library:merge-duplicates [--dry-run] [--force]` (`LibraryMerger`) merges existing duplicates into the oldest record, one transaction per group: re-points tracks/albums/plays/playlist entries/metadata, keeps the earliest `favorited_at` and the processed cover, deletes the rest. Idempotent. Scheduled weekly (Sunday 04:00, `--force`); ids of merged records disappear, so clients that cached one get a 404 until they re-read the list.

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

## Infrastructure (Docker)

Defined in `compose.yaml` via Laravel Sail:
- **laravel.test** – PHP 8.4 app server (port 80)
- **redis** – cache, sessions, and queue backend
- **mosquitto** – MQTT broker (port 1883; WebSockets on 9001 for browser live updates)
- **meilisearch** – search (port 7700)
- **Vite dev server** – port 5173

Client devices find the server via mDNS (`_remoment._tcp`, TXT `path=/api`, `api_version`), advertised by Avahi on the Docker **host** — set up by `ansible/setup.yml`, not inside a container. See `docs/architecture/discovery-for-clients.md`.

---

## Frontend

Blade templates + Livewire 3 for real-time UI. Alpine.js for client-side interactivity. Rendering stays in Livewire: `public/js/remoment-live.js` only listens to MQTT over WebSockets and triggers `$wire.$refresh()`, falling back to 1s polling when the broker is unreachable (see `docs/architecture/live-updates.md`). Tailwind CSS and Font Awesome loaded via CDN (not compiled). The layout (`resources/views/layouts/app.blade.php`) provides a persistent sidebar navigation.

### Livewire Components

- `DeviceCard` (`app/Livewire/DeviceCard.php`) — device card with transport, volume, mute, and click-to-seek; on the single-device page (`standalone`) also lyrics and "Add to library" for the playing track (`Concerns/ManagesTrackExtras`); refreshes on MQTT push; shows color gradient from `ArtworkCache`; includes Multiroom button for capable devices
- `DeviceQueue` (`app/Livewire/DeviceQueue.php`) — "Up Next" list on the device page for `queue`-capable devices, loaded lazily via `wire:init`
- `SystemHealth` (`app/Livewire/SystemHealth.php`) — `/settings/health`: MQTT broker reachability, scheduler/queue-worker heartbeats, pending/failed jobs, per-device listener state and last seen
- `DeviceSourceManager` (`app/Livewire/DeviceSourceManager.php`) — source list with hide/show toggle, drag-to-reorder, and activate button
- `MultiroomPresets` (`app/Livewire/MultiroomPresets.php`) — create/activate/delete named multiroom groupings
- `DeviceHistory` (`app/Livewire/DeviceHistory.php`) — last 10 unique tracks for a device
- `PlayHistory` (`app/Livewire/PlayHistory.php`) — paginated play history with device/source filters
- `ClientManager` (`app/Livewire/ClientManager.php`) — approve/reject pending registrations, edit client name/type/device assignment, regenerate tokens
- `PlayerBar` (`app/Livewire/PlayerBar.php`) — sticky bottom player for the device pinned in this browser (cookie, `SelectedDevice`); library play buttons (`<x-play-button>`) play on it directly (see `docs/frontend/player-bar.md`)
- `SetupWizard` (`app/Livewire/SetupWizard.php`) — first-time setup checklist: add devices, client devices, library source (see `docs/architecture/setup-wizard.md`)

### Web Pages

- `/setup` — first-time setup wizard (see `docs/architecture/setup-wizard.md`); auto-redirected here for a logged-in admin while zero devices exist
- `/devices` — responsive device grid dashboard (playing devices get wide card, others get compact)
- `/devices/create` — add device with cascading brand→product→driver form (Alpine.js)
- `/devices/{id}` — device detail: nowplaying + up next queue + source manager + info panel
- `/devices/{id}/edit` — edit device name, IP, brand, product
- `/history` — full play history browser
- `/stats` — listening statistics (top artists, devices, hourly heatmap)
- `/multiroom` — multiroom session management and presets
- `/receiver` — full-screen now-playing display; fetches `GET /api/devices`, lets user pick device (or auto-selects if only one); re-fetches `GET /api/devices/{id}` on MQTT push (polling every 3s without a broker) and supports play/pause/next/previous, seek and mute via the REST API. Accepts `?device={id}` query parameter to skip the picker.
- `/artists` — artist library browser
- `/albums/{id}` — album detail with track listing
- `/settings` — overview of listeners, MQTT config
- `/settings/users` — user management table
- `/settings/health` — supervisor, scheduler, queue worker, pending jobs, MQTT broker and per-device listener health
- `/settings/dlna` — discover DLNA servers, trigger library scans
- `/settings/spotify-connect` — map Spotify Connect speaker names to local devices
- `/settings/clients` — manage client device registrations; approve/reject pending, assign devices, view tokens; build/download the SD card artwork zips, one per screen size (see `docs/architecture/sd-card-export.md`)

### Controllers

- `DeviceController` (`app/Http/Controllers/DeviceController.php`) — full CRUD + standby + source activation (web)
- `Api/DeviceController` (`app/Http/Controllers/Api/DeviceController.php`) — REST API
- `HistoryController` — play history views
- `SettingsController` — settings, listeners, DLNA, Spotify Connect, client devices
- `Api/ClientController` (`app/Http/Controllers/Api/ClientController.php`) — client registration, status polling, device list, heartbeat
- `StatsController` — statistics views

---

## Code Style

Laravel Pint with the `laravel` preset (`pint.json`). Run `./vendor/bin/pint` before committing.
