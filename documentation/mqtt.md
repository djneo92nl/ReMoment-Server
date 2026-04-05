# MQTT Integration

ReMomentServer publishes real-time device state to an MQTT broker (Eclipse Mosquitto), enabling IoT integrations, dashboards, and automations to subscribe to playback events without polling the API.

---

## Broker

The Mosquitto broker runs in Docker and is accessible on:

| Protocol | Port |
|----------|------|
| MQTT | 1883 |
| WebSocket | 9001 |

Configure the connection in `.env`:

```dotenv
MQTT_HOST=localhost
MQTT_PORT=1883
MQTT_CLIENT_ID=remoment-server
```

---

## Topic Structure

All topics are nested under `remoment/player/{deviceId}/`. The device ID matches the `id` column in the `devices` table and is included in every API response as `mqtt_topic`.

| Topic | Trigger | Payload type |
|-------|---------|--------------|
| `remoment/player/{id}/data` | Track changes | JSON |
| `remoment/player/{id}/progress` | Playback progresses | String (number) |

---

## Topics

### `remoment/player/{id}/data`

Published when the now-playing track changes (`NowPlayingUpdated` event). Contains the track name and primary artist.

**Payload**

```json
{
  "track": "Venus",
  "artist": "Shocking Blue"
}
```

Both fields may be `null` if the information is not available (e.g. radio streams without metadata).

### `remoment/player/{id}/progress`

Published periodically as playback progresses (`ProgressUpdated` event). The value is a percentage (0–100) as a plain string.

**Payload**

```
"42"
```

---

## Subscribing

Using the `mosquitto_sub` CLI:

```bash
# All events for a single device
mosquitto_sub -h localhost -t "remoment/player/1/#"

# Now-playing changes across all devices
mosquitto_sub -h localhost -t "remoment/player/+/data"

# Progress updates across all devices
mosquitto_sub -h localhost -t "remoment/player/+/progress"
```

---

## Implementation

Publishing is handled by `app/Services/MqttService.php` via the `php-mqtt/client` package. A new connection is opened and closed per publish call — there is no persistent connection.

Listeners that publish to MQTT:

| Listener | Event | Topic |
|----------|-------|-------|
| `app/Listeners/Device/PublishNowPlayingToMqtt.php` | `NowPlayingUpdated` | `…/data` |
| `app/Listeners/Device/PublishProgressToMqtt.php` | `ProgressUpdated` | `…/progress` |

Both listeners run synchronously (not queued) so MQTT messages are published immediately when the device sends a notification.

---

## Finding a Device's MQTT Topic

The `mqtt_topic` field is included in all API responses:

```bash
curl http://localhost/api/devices
```

```json
{
  "data": [
    {
      "id": 1,
      "device_name": "Living Room",
      "mqtt_topic": "remoment/player/1",
      ...
    }
  ]
}
```

Subscribe to `{mqtt_topic}/#` to receive all events for that device.
