# Sub devices

A **sub device** is a device connected to another device, its **host**: a Chromecast on a TV's HDMI input, a BeoLink Converter a player borrows. Both stay full devices. Design: [docs/architecture/sub-devices.md](../architecture/sub-devices.md). Everything here is tested with fakes; hardware behaviour is unverified.

## Reading the relation (open)

`GET /api/devices/{id}` carries it, both ways:

```json
{
  "capabilities": ["media_controls", "sub_devices"],
  "host": null,
  "sub_devices": [{ "id": 7, "name": "Chromecast", "via": "hdmi_1", "active": true }]
}
```

- `host`: on a sub device, the host that has it selected now, else the oldest link (`{ id, name, via }`); `null` when the device is no sub device.
- `sub_devices`: on a host, the sub devices connected to it. `via` is the host's input or source id (null for a link with none); `active` is true while the host has that input or source selected, false while not, `null` while unknown (the host does not report its selected source: a Philips TV).
- `sub_devices` in `capabilities` marks a host.

## Managing links (admin token)

```
GET    /api/admin/devices/{id}/links             → { sub_devices: [Link], hosts: [Link] }   links where the device is the host / the sub device
GET    /api/admin/devices/{id}/link-suggestions  → { suggestions: [{ device_id, device_name, kind, via, borrowing }] }   asks the device (an ASE player's Products): 502 when it does not answer
POST   /api/admin/device-links                   { device_id, host_device_id, kind, via?, meta? } → 201 Link
PUT    /api/admin/device-links/{link}            { via?, meta? } → Link
DELETE /api/admin/device-links/{link}            → { status: "ok" }
```

`Link` is `{ id, device_id, device_name, host_device_id, host_name, kind, via, meta, active }`. `device_id` is the sub device. `kind` is `borrow` (a source the host borrowed: a converter; `via` may be empty for every source it provides), `input` (an HDMI input) or `source` (another source of the host); `input` and `source` need `via`.

Errors: `401` without the admin token, `422` `validation` for a bad body, `422` `invalid` with the reason for a link the rules refuse (a link to itself, the same link twice, a host that is a sub device, a sub device that is a host: sub devices go one level deep), `404` unknown link or device.

MCP: tool `device_links` (ADMIN) with actions `list`, `suggestions`, `add`, `update`, `remove`; `get_device` shows the relation. OpenAPI: `listDeviceLinks`, `listDeviceLinkSuggestions`, `createDeviceLink`, `updateDeviceLink`, `deleteDeviceLink`.

## What a link does on the host (open)

While a link is active (the host has that input or source selected):

- `POST /api/devices/{host}/play|pause|next|previous|stop`, `PUT …/seek`, `GET|POST|PUT|DELETE …/queue`, `PUT …/shuffle|repeat|like` are executed by the sub device's driver, as far as it implements them; volume, mute, power, other sources, multiroom, radio and library playback stay with the host. The host's `capabilities` list the sub device's playback capabilities while the link is active.
- `now_playing` of the host is the sub device's (with its artwork and `modes`) while the sub device plays or pauses something, else the host's own. MQTT is unchanged: the sub device publishes on its own topic.
- `GET /api/devices/{host}/sources` has the link in the list: `sub_device: { id, name }` on the source it is connected through, and an entry of its own (category `link`) when the host lists no such source; activate it with `POST …/sources/activate`.

## Link settings (`meta`)

`meta` of a link holds only these (anything else is a 422 `invalid`):

```json
{
  "on_activate":   [{ "action": "power_on" }, { "action": "source", "source_id": "CD" }],
  "on_deactivate": [{ "action": "power_off" }],
  "follow": "from_standby",
  "follow_off_after": 20
}
```

- `on_activate` / `on_deactivate`: up to five actions for the **sub device**, run when the link becomes active or ends, whoever changed the host's input (`power_on`, `power_off`, `source` with `source_id`). They need the queue worker.
- `follow`: `off`, `from_standby` (default) or `always`: when the sub device starts playing, the host selects the link's `via` (only `input` and `source` links). `from_standby` switches only a host in standby (a cast wakes the TV, and never takes the screen from another input); `always` also takes it from another input. After a short debounce (`SUB_DEVICE_FOLLOW_DEBOUNCE`, 5 s) and only if the session still plays; the Chromecast's idle backdrop is not a session. Needs the queue worker.
- `follow_off_after`: minutes (1 to 1440) or null. The host goes to standby that long after the sub device stops, only if the follow rule woke it.
- `state` (`woke_host`, ...) is the server's own; it is returned but never set, and an edit of the settings keeps it.
