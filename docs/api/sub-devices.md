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
