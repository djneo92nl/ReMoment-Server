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
← { registration_token: "…", status: "pending" }
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

Base URL: `http://{server}/api`

All endpoints return JSON. No global authentication header is needed — the token passed in the URL is the credential.

---

### `POST /api/clients/register`

Registers the client and returns a `registration_token` used to poll for approval. Safe to call on every boot when a `hardware_id` is supplied — re-registering a known `hardware_id` updates the existing record (IP, firmware) and returns the same `registration_token`.

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
  "status": "pending"
}
```

**Response — re-registration with known `hardware_id` (HTTP 200):**

```json
{
  "registration_token": "VZdfJspbGT7xD9avqXDFoRsfw31EKoiT6qDLenxDD0h37uSi",
  "status": "pending"
}
```

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

Device shape is identical to `GET /api/devices` (`DeviceListResource`). For `multi` clients with no explicit assignments, all devices are returned. Returns `HTTP 404` for an unknown token.

---

### `PUT /api/clients/{api_token}/heartbeat`

Updates the client's IP address, firmware version, build number, and `last_seen_at` on the server. The admin sees this in the UI. Call this periodically — every 5–15 minutes is sufficient.

**Request body** (all optional):

```json
{
  "firmware_version": "1.0.3",
  "build_number": 43
}
```

The server updates `ip_address` from the TCP connection automatically.

**Response:**

```json
{ "status": "ok" }
```

Returns `HTTP 404` for an unknown token.

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
```

Always check the `capabilities` array from `GET /devices` before showing transport controls — a device without `media_controls` will return `422`.

### MQTT (optional)

Each device publishes to `remoment/player/{id}/data` (track change) and `remoment/player/{id}/progress` (every second). The `mqtt_topic` field in `DeviceListResource` gives you the base topic. MQTT lets you avoid polling for progress updates.

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
