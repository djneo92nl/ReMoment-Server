# ReMoment Server — JSON API

The JSON API provides a universal interface for querying and controlling all connected audio devices regardless of brand or driver. It abstracts over Bang & Olufsen (ASE) and Sonos devices through a common contract layer.

Base URL: `http://<host>/api`

All responses use JSON. Error responses follow the shape:

```json
{
  "error": "<code>",
  "message": "<human-readable description>"
}
```

---

## Devices

### List devices

```
GET /api/devices
```

Returns all registered devices with their current state and capabilities.

**Response `200`**

```json
{
  "data": [
    {
      "id": 1,
      "device_name": "Living Room",
      "device_brand_name": "Bang & Olufsen",
      "device_product_type": "BeoSound Shape",
      "device_driver_name": "ASE",
      "ip_address": "192.168.1.10",
      "state": "playing",
      "last_seen": "2026-04-05T10:00:00.000000Z",
      "capabilities": ["media_controls", "volume_control"],
      "mqtt_topic": "remoment/player/1"
    }
  ]
}
```

**State values**

| Value | Description |
|-------|-------------|
| `playing` | Device is actively playing audio |
| `paused` | Playback is paused |
| `standby` | Device is on but idle |
| `unreachable` | Device could not be reached |

**Capabilities**

| Value | Description |
|-------|-------------|
| `media_controls` | Supports play, pause, stop, next, previous |
| `volume_control` | Supports get/set volume |

---

### Get device

```
GET /api/devices/{id}
```

Returns a single device including its current now-playing information.

**Response `200`**

```json
{
  "data": {
    "id": 1,
    "device_name": "Living Room",
    "device_brand_name": "Bang & Olufsen",
    "device_product_type": "BeoSound Shape",
    "device_driver_name": "ASE",
    "ip_address": "192.168.1.10",
    "state": "playing",
    "last_seen": "2026-04-05T10:00:00.000000Z",
    "capabilities": ["media_controls", "volume_control"],
    "mqtt_topic": "remoment/player/1",
    "now_playing": {
      "track": {
        "name": "Venus",
        "artist": { "name": "Shocking Blue" },
        "duration": 214
      },
      "album": { "name": "At Home" },
      "state": "playing",
      "position": 42,
      "type": "music",
      "platform": "spotify",
      "source": { "name": "Spotify" }
    }
  }
}
```

`now_playing` is `null` when the device is unreachable or nothing is cached yet.

---

## Transport Controls

```
POST /api/devices/{id}/{action}
```

Send a transport command to a device. Requires the `media_controls` capability.

**Actions**

| Action | Description |
|--------|-------------|
| `play` | Resume or start playback |
| `pause` | Pause playback |
| `stop` | Stop playback |
| `next` | Skip to the next track |
| `previous` | Go back to the previous track |

**Response `200`**

```json
{
  "status": "ok",
  "action": "pause"
}
```

**Error responses**

| Status | Code | Reason |
|--------|------|--------|
| `503` | `unreachable` | Device is not reachable |
| `422` | `unsupported` | Device does not have `media_controls` capability |
| `502` | `driver_error` | Device responded with an error |
| `404` | — | Unknown action (not in allowed list) |

---

## Volume

### Get volume

```
GET /api/devices/{id}/volume
```

Returns the current volume level. Requires the `volume_control` capability.

**Response `200`**

```json
{
  "volume": 35
}
```

---

### Set volume

```
PUT /api/devices/{id}/volume
```

Sets the volume level. Requires the `volume_control` capability.

**Request body**

```json
{
  "volume": 50
}
```

| Field | Type | Constraints |
|-------|------|-------------|
| `volume` | integer | Required, 0–100 |

**Response `200`**

```json
{
  "volume": 50
}
```

The returned `volume` is the value confirmed by the driver, which may differ slightly from the requested value on some devices.

---

## MQTT Integration

Every device publishes real-time state changes to MQTT topics under its base topic. The base topic is included in every API response as `mqtt_topic`.

**Base topic:** `remoment/player/{id}`

| Topic | Payload | Description |
|-------|---------|-------------|
| `remoment/player/{id}/data` | JSON | Published when now-playing changes. Contains `track` and `artist`. |
| `remoment/player/{id}/progress` | String (0–100) | Published periodically with playback progress as a percentage. |

The MQTT broker runs on port `1883`. Example subscription using mosquitto:

```bash
mosquitto_sub -h localhost -t "remoment/player/1/#"
```

---

## Error Reference

All errors use a consistent JSON shape:

```json
{
  "error": "<code>",
  "message": "<description>"
}
```

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `unreachable` | 503 | Device is offline or not responding |
| `unsupported` | 422 | The device does not support the requested capability |
| `driver_error` | 502 | The driver made a request to the device but it failed |
| `server_error` | 500 | Unexpected server-side error |
