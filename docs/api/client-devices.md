# Client Device API

Integration guide for firmware and software clients (ESP8266, ESP32, Raspberry Pi, Squeezebox Touch, etc.) that control ReMoment-managed media players.

---

## Concepts

A **client device** is anything that consumes the ReMoment REST API to display now-playing info or send transport commands. The server gives each client a filtered view of devices — a single-room ESP8266 sees only its one player; a Pi in the hallway can see all of them.

Two client types exist:

| Type | Use case |
|------|----------|
| `single` | Simple microcontroller (ESP8266, OLED display) controlling one player |
| `multi` | Smarter device (ESP32-S3, Pi, software integration) controlling several or all players |

Clients are **not** trusted by default. Every registration starts as `pending` and must be approved by an admin in the web UI at `/settings/clients`. The MQTT topics remain open and unauthenticated — the client API is a convenience layer for discovery and device assignment, not a security boundary.

---

## Registration Flow

```
Client boot
    │
    ▼
POST /api/clients/register
    { hardware_id, firmware_version, build_number, metadata }
    │
    ▼
← { registration_token: "…", pairing_code: "K7M3QX", status: "pending" }
    │
    ▼
Show "Waiting for approval" + pairing_code on screen
    │
    ▼
Poll GET /api/clients/status/{registration_token}
    every 10–30s until status == "approved"
    │
    ▼ (admin approves in UI)
← { status: "approved", api_token: "…", type: "single"|"multi", devices: […] }
    │
    ▼
Store api_token in NVS / flash
    │
    ▼
GET /api/clients/{api_token}/devices  → assigned device list
    │
    ▼
Normal operation: poll /api/devices/{id} for now-playing, 
send transport commands, send PUT /heartbeat periodically
```

**Important:** `api_token` does not change after approval unless the admin explicitly regenerates it. Store it in persistent NVS/flash and reuse it across reboots. Only call `register` again if the stored token is lost.

---

## Endpoints

Base URL: `http://{server}/api` — find `{server}` via mDNS (`_remoment._tcp`, see `docs/architecture/discovery-for-clients.md`), then call `GET /api/info` (`docs/api/server-info.md`) for the MQTT broker address.

All endpoints return JSON. No global authentication header is needed — the token passed in the URL is the credential.

---

### `POST /api/clients/register`

Registers the client and returns a `registration_token` used to poll for approval, plus a short `pairing_code` to show on the client's screen. Safe to call on every boot when a `hardware_id` is supplied — re-registering a known `hardware_id` updates the existing record (IP, firmware) and returns the same `registration_token` and `pairing_code`.

**Request body** (all fields optional):

```json
{
  "hardware_id": "AA:BB:CC:DD:EE:FF",
  "firmware_version": "1.0.2",
  "build_number": 42,
  "metadata": {
    "board": "ESP32-S3",
    "notes": "kitchen unit"
  }
}
```

| Field | Notes |
|-------|-------|
| `hardware_id` | MAC address or chip ID. Enables idempotent re-registration — omit only if you have no stable identifier. |
| `firmware_version` | Shown in the admin UI. Semver string or any label. |
| `build_number` | Integer CI build number. |
| `metadata` | Arbitrary JSON object. Shown verbatim in the admin UI. |

The server records the caller's IP address automatically from the TCP connection.

**Response — first registration (HTTP 201):**

```json
{
  "registration_token": "VZdfJspbGT7xD9avqXDFoRsfw31EKoiT6qDLenxDD0h37uSi",
  "pairing_code": "K7M3QX",
  "status": "pending"
}
```

**Response — re-registration with known `hardware_id` (HTTP 200):**

```json
{
  "registration_token": "VZdfJspbGT7xD9avqXDFoRsfw31EKoiT6qDLenxDD0h37uSi",
  "pairing_code": "K7M3QX",
  "status": "pending"
}
```

`pairing_code` is always 6 characters from the alphabet `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (uppercase, no `0`/`O`/`1`/`I`), so it is safe to render in any font. It is stable for a given `hardware_id`. Show it on the client's screen while waiting for approval — the admin sees the same code next to the pending registration at `/settings/clients` and uses it to pick the right device. It is not a credential and is never needed in a request.

If the client was already approved, `status` will be `"approved"` but the `registration_token` is returned, not the `api_token`. Use `GET /status/{registration_token}` to retrieve the `api_token`.

---

### `GET /api/clients/status/{registration_token}`

Polls approval status. Call this after registration until `status` is `approved`.

**Recommended polling interval:** 15–30 seconds. The admin must manually approve in the UI; there is no push notification.

**Response — pending:**

```json
{ "status": "pending" }
```

**Response — approved:**

```json
{
  "status": "approved",
  "type": "single",
  "api_token": "EDoxWoIDAucWfP46sojZDQZiGVi4vLHe88EbZQagimE21pJ0",
  "devices": [
    {
      "id": 1,
      "device_name": "Living Room",
      "device_brand_name": "Bang & Olufsen",
      "device_product_type": "Beolab 28",
      "device_driver_name": "ASE Music Player",
      "ip_address": "192.168.1.10",
      "state": "playing",
      "last_seen": "2026-07-07T10:00:00",
      "capabilities": ["media_controls", "volume_control", "source_control", "source_activation", "multi_room"],
      "mqtt_topic": "remoment/player/1"
    }
  ]
}
```

`devices` is the complete list of devices this client is allowed to control. For `single` clients this is always one entry; for `multi` clients it may be many. Store the `api_token` immediately to persistent storage — this is the only place it is returned alongside the approval notification.

Returns `HTTP 404` if the `registration_token` is unknown.

---

### `GET /api/clients/{api_token}/devices`

Returns the current assigned device list and updates `last_seen_at` on the client record. Use this to refresh device IDs after firmware updates or to detect new assignments.

**Response:**

```json
{
  "devices": [ DeviceListResource, … ]
}
```

Device shape is identical to `GET /api/devices` (`DeviceListResource`). For `multi` clients with no explicit assignments, all devices are returned. While Spotify plays on a mapped speaker that is in the client's list, the Spotify virtual device is left out (here and in the approved `status` response) and the speaker carries the playback and Spotify's controls; a client whose list doesn't include that speaker (e.g. assigned only the Spotify device) keeps the Spotify device. See "Spotify routing" in CLAUDE.md. Returns `HTTP 404` for an unknown token.

---

### `PUT /api/clients/{api_token}/heartbeat`

Updates the client's IP address, firmware version, build number, and `last_seen_at` on the server. The admin sees this in the UI. Call this periodically — every 5–15 minutes is sufficient.

**Request body** (all optional):

```json
{
  "firmware_version": "1.0.3",
  "build_number": 43,
  "battery_percent": 82,
  "battery_charging": false
}
```

`battery_percent` (integer 0–100) and `battery_charging` (boolean) are for battery-powered clients; omit them if there is no battery. They are stored on the client (shown in `/settings/clients`) and overwrite the previous values when sent; omitted fields keep their last value.

Suggested client behavior (decided on the client, no server round-trip): show a battery icon only below 20% (yellow, red below 10%) and the battery level on the client's settings screen at any level; show a plug icon while `battery_charging`; below 20% lower the screen brightness and turn the LED off.

The server updates `ip_address` from the TCP connection automatically.

**Response:**

```json
{ "status": "ok" }
```

Returns `HTTP 404` for an unknown token.

---

### `GET /api/clients/{api_token}/artwork`

Lists processed artwork so a client can pre-cache it (e.g. on SD) before it is played, newest first, so a client sweeping while idle gets the most likely covers early. Items are keyed by `hash`, the same `md5(original image URL)` that appears in every artwork path (`/storage/artwork/{hash}/320.jpg`) of `now_playing.artwork` and the MQTT `/data` payload, so a cached file is found again when that album or source plays.

**What is listed, in this order:**
1. The eight generated source logos (`kind: "source"`: Spotify, Bluetooth, AirPlay/Cast, radio, line-in, CD, TV, music note), first page only. They are rendered on the spot if missing, so they are always there.
2. Playlist covers (`kind: "playlist"`), first page only: the server-generated 2×2 composites of each playlist's top 4 album covers (and a playlist's own image when it has no album covers), most recently played playlist first — the same `hash` as `artwork` in `GET /api/library/playlists`. A playlist whose cover is simply one album's cover isn't repeated here: that cover is an album item. See `docs/api/library.md` (Playlist artwork).
3. Library albums (`kind: "album"`) that have been played, by their last play in the playback history, newest first.
4. The rest of the library (`albums` table: Spotify library import and DLNA scans), by album id.

The library is shared, so this is the same for every client, whatever devices it is assigned. Only covers whose 320, 120 and every background file (1024×600 and 320×480) have all been processed are listed; others are skipped (not queued by this call). `artwork:prerender` (daily) processes playlist covers and the last 500 played albums and `artwork:backfill` (daily) the rest of the library, so skipped covers appear on a later sweep.

**Query:** `cursor` (optional) — the `next_cursor` of the previous page; omit for the first page. Treat it as opaque.

**Response:**

```json
{
  "data": [
    {
      "kind": "source",
      "hash": "6910ad50fb63f947ac24032558d3b570",
      "proxy_320": "http://remoment.local/storage/artwork/6910ad50fb63f947ac24032558d3b570/320.jpg",
      "proxy_120": "http://remoment.local/storage/artwork/6910ad50fb63f947ac24032558d3b570/120.jpg",
      "proxy_bg": "http://remoment.local/storage/artwork/6910ad50fb63f947ac24032558d3b570/bg_1024x600.jpg",
      "proxy_bg_320x480": "http://remoment.local/storage/artwork/6910ad50fb63f947ac24032558d3b570/bg_320x480.jpg",
      "proxy_bg_480x480": "http://remoment.local/storage/artwork/6910ad50fb63f947ac24032558d3b570/bg_480x480.jpg"
    },
    {
      "kind": "album",
      "hash": "9b2d5c0a7e1f4b3c8d6a2e5f1c0b7a9d",
      "proxy_320": "http://remoment.local/storage/artwork/9b2d5c0a7e1f4b3c8d6a2e5f1c0b7a9d/320.jpg",
      "proxy_120": "http://remoment.local/storage/artwork/9b2d5c0a7e1f4b3c8d6a2e5f1c0b7a9d/120.jpg",
      "proxy_bg": "http://remoment.local/storage/artwork/9b2d5c0a7e1f4b3c8d6a2e5f1c0b7a9d/bg_1024x600.jpg",
      "proxy_bg_320x480": "http://remoment.local/storage/artwork/9b2d5c0a7e1f4b3c8d6a2e5f1c0b7a9d/bg_320x480.jpg"
    }
  ],
  "next_cursor": 200
}
```

URLs have the same form as in `now_playing.artwork`: take the path from `/storage/` on and prefix `artwork_base_url` from `GET /api/info`. All files are baseline JPEGs (320×320, 120×120, 1024×600, 320×480). Cache the background for your screen: a client picks `proxy_bg_{width}x{height}` for its own screen (e.g. `proxy_bg_320x480` on the 3.5" portrait client) and falls back to `proxy_bg` (1024×600) when that key is absent.

**Pagination:** each page scans 200 albums of the order above (plus the logos and playlist covers on the first page), and `next_cursor` is set when there may be more. A page can therefore hold fewer than 200 album items, or none, while `next_cursor` is still set — keep requesting `?cursor={next_cursor}` until it is `null`. The cursor is a position in the recency order, so plays during a sweep can shift it a little: an item may be listed twice (skip hashes you already have), and the album that just started playing may be passed over — you get that one from now-playing anyway. Albums sharing a cover are listed once per page.

Returns `HTTP 404` for an unknown token and `422` for an invalid `cursor`.

**Suggested firmware use:** run a sweep after boot or once a day while idle, download only hashes missing from the SD cache, newest first, and throttle downloads so playback updates stay responsive. For a first fill, the SD card export (admin, `/settings/clients`, see `docs/architecture/sd-card-export.md`) is faster: it holds the same hashes in the card layout `remoment/covers/{hash}.jpg` and `remoment/backgrounds/{hash}.jpg`.

---

## Error Responses

All endpoints return standard HTTP status codes. Non-2xx responses have a JSON body:

```json
{ "message": "…" }
```

Validation errors (HTTP 422) return:

```json
{
  "message": "The build number must be an integer.",
  "errors": {
    "build_number": ["The build number must be an integer."]
  }
}
```

---

## Firmware Implementation Notes

### NVS / flash storage

Store two values in persistent storage:

| Key | Value |
|-----|-------|
| `registration_token` | Used to poll status and recover `api_token` after reboot |
| `api_token` | Used for all normal operation calls |

On boot:
1. If `api_token` is set → call `GET /devices` directly, skip registration
2. If only `registration_token` is set → poll `GET /status/{token}` until approved
3. If neither → call `POST /register` and store `registration_token`

While waiting for approval, show the `pairing_code` on screen. Either store it alongside `registration_token`, or call `POST /register` again on boot (with the same `hardware_id`) to get it back — the code does not change.

### Polling the receiver

Once you have a device ID, use the standard device API — the client API is only for registration and device discovery.

```
GET /api/devices/{id}          → full now-playing state (poll every 3–5s)
GET /api/devices/{id}/volume   → current volume
```

See `DeviceDetailResource` in the main REST API docs for the full `now_playing` shape.

### Transport control

```
POST /api/devices/{id}/play
POST /api/devices/{id}/pause
POST /api/devices/{id}/next
POST /api/devices/{id}/previous
PUT  /api/devices/{id}/seek              body: { "position": 90 }   → { "status": "ok", "position": 90 }   (seconds; capability `seek`)
GET  /api/devices/{id}/queue?limit=20    → { "up_next": [ { name, artist, album, image, duration, uri, artwork } ] }   (capability `queue`)
POST /api/devices/{id}/queue/jump        body: { "position": 2 }    → { "status": "ok", "position": 2 }    (capability `queue_jump`)
```

`queue/jump` plays the entry `position` places ahead: 1 is the first item of `up_next`, 2 the second, and so on. Sonos selects that queue entry directly; Spotify can only skip forward, so it skips `position` times (the entries before the target start for an instant). `artwork` of a queue item is the same small object as in the library API (`proxy_120`, `proxy_320`), or `null`.

Settings capabilities (`sound_adjustment`, `bluetooth`, `device_info`) have their own endpoints, see `docs/api/device-settings.md`.

Always check the `capabilities` array from `GET /devices` before showing transport controls — a device without `media_controls` will return `422`. Capabilities can change while running: a speaker Spotify is routed to gains `media_controls`, `seek`, `queue`, `queue_jump`, `shuffle`, `repeat` and `like` for as long as it plays (or is paused on) Spotify, so refresh the device list now and then, and treat non-null `/modes` values as supported.

### MQTT (optional)

Each device publishes to `remoment/player/{id}/data` (track change; retained, an empty payload means nothing is playing), `/progress` (every second), `/state`, `/volume` (`{ "volume": 45, "muted": false }`) and `/modes` (`{ "shuffle", "repeat", "liked" }`), the last four retained. The `mqtt_topic` field in `DeviceListResource` gives you the base topic. MQTT lets you avoid polling for progress updates. See the MQTT table in CLAUDE.md and `docs/architecture/live-updates.md` for payloads.

---

## Example: ESP32 Arduino sketch outline

```cpp
// On boot
String regToken = nvs.getString("reg_token");
String apiToken = nvs.getString("api_token");

if (apiToken.isEmpty()) {
    if (regToken.isEmpty()) {
        // First boot — register
        String body = "{\"hardware_id\":\"" + getMacAddress() + "\","
                      "\"firmware_version\":\"1.0.2\",\"build_number\":42}";
        HttpResponse r = http.post("/api/clients/register", body);
        regToken = r.json["registration_token"];
        nvs.putString("reg_token", regToken);
    }
    // Poll until approved
    while (true) {
        delay(15000);
        HttpResponse r = http.get("/api/clients/status/" + regToken);
        if (r.json["status"] == "approved") {
            apiToken = r.json["api_token"];
            nvs.putString("api_token", apiToken);
            deviceId = r.json["devices"][0]["id"];
            break;
        }
    }
}

// Normal loop — poll now-playing every 3s
while (true) {
    HttpResponse r = http.get("/api/devices/" + String(deviceId));
    NowPlaying np = parseNowPlaying(r.json);
    display.update(np);
    delay(3000);
}
```
