# Admin devices and discovery (`/api/admin/devices`, `/api/admin/discovery`)

Adding, changing, hiding and removing devices, and the inbox of what scans found, behind the admin token (`401 unauthenticated` without one). The actions of `/devices/create`, `/devices/{id}/edit` and `/settings/devices`, through `App\Domain\Device\DeviceAdmin`, `DeviceScan` and `DiscoveryInbox` (the web pages use them too). Reading devices stays open (`GET /api/devices`). MCP: tool `manage_devices`.

```
GET    /api/admin/device-models                 → { data: [{ brand, product, driver_name, virtual }] }
GET    /api/admin/devices                       → { data: [Device] }       every device, hidden ones too
POST   /api/admin/devices                       { device_name, device_brand_name, device_product_type, ip_address?, hidden? }  → 201 { data: Device }
GET    /api/admin/devices/{id}                  → { data: Device }
PUT    /api/admin/devices/{id}                  { device_name?, ip_address?, hidden?, device_brand_name + device_product_type? }  → { data: Device }
DELETE /api/admin/devices/{id}                  → { status: "ok" }

GET    /api/admin/discovery                     → { scan, data: [Found], ignored: [Found] }
POST   /api/admin/discovery/scan                { hosts?: ["192.168.1.9"] }  → 202 { scan }
POST   /api/admin/discovery/add-all             → { added, device_ids }
POST   /api/admin/discovery/{id}/add            { name? }  → 201 { data: Device }
POST   /api/admin/discovery/{id}/ignore         → { data: Found }
POST   /api/admin/discovery/{id}/restore        → { data: Found }
```

`Device` is the list shape of `GET /api/devices` (`DeviceListResource`) plus `hidden`.

## Add, edit, remove

- **Models.** `device-models` lists what a device can be added as (the models of `config/devices.php`). `virtual: true` means no network address (Spotify). The driver is **never sent by the client**: it follows from `device_brand_name` + `device_product_type`, an unknown pair is `422` on `device_product_type`.
- **Add.** `ip_address` is required unless the model is virtual. A manually added device has no `last_seen` until its listener reports.
- **Edit.** Only the keys sent change. A new brand and product must come as a pair and pick the driver again. An address cannot be cleared on a model that needs one (`422`). Changing the address makes the server rewrite the control URLs stored for a UPnP renderer and restart the device's listener (existing behaviour). `hidden: true` keeps the device stored and listened to but out of the pickers.
- **Remove.** Deletes the device with its meta; its links to other devices end with it. Playlists, history and library are not touched.

## Discovery

A scan probes every registered brand (SSDP, mDNS, Sonos topology) plus the extra `hosts` (IPv4 addresses or http(s) URLs; anything else is dropped), and **records the finds in the inbox**: a find that is already a device only refreshes that device. It runs on the queue because it takes seconds:

1. `POST /discovery/scan` → `202 { scan: { state: "running", started_at } }`. A scan that is already running is not started twice (the same state comes back).
2. Poll `GET /discovery`. `scan.state` is `idle` (never run), `running`, `done` (`scan.result` is `{ found, new, known, summary, diagnostics }`) or `failed` (`scan.error`; also when a running scan did not report back for 3 minutes, usually no queue worker).
3. `data` holds the finds that are not ignored, `ignored` the ones set aside. `Found` is `{ id, device_name, ip_address, device_brand_name, device_product_type, device_driver_name, last_seen_at, ignored }`.
4. `add` turns a find into a device (`name` renames it, default the name it was found with) and removes it from the inbox; `add-all` does so for everything not ignored; `ignore` / `restore` move it between the lists.

**Unverified on real networks:** the scan itself is the existing discovery code; multicast does not cross Docker, so inside a container hosts may be needed (`hosts`), as on the web page.
