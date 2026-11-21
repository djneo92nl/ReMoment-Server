# Multiroom presets (`/api/multiroom/presets`)

Named groups of devices, the **first device the host**: activating one makes the others join it. **Open like multiroom itself, no admin token** (decided 2026-10-26, see workstream 8b): the web page (`/multiroom`) has no login either, so web and API agree. One implementation, `App\Domain\Multiroom\Presets`. MCP: tool `multiroom_presets`. Per-device join and leave are [the multiroom endpoints](../reference/rest-api.md#multiroom) and the `multiroom` tool; groups for devices without native multiroom are [sync groups](sync-groups.md).

```
GET    /api/multiroom/presets                    → { data: [Preset] }
POST   /api/multiroom/presets                    { name, device_ids }        → 201 { data: Preset }
PUT    /api/multiroom/presets/{id}               { name?, device_ids? }      → { data: Preset }
DELETE /api/multiroom/presets/{id}               → { status: "ok" }
POST   /api/multiroom/presets/{id}/activate      → { status, host, joined, failed }
```

`Preset` is `{ id, name, device_ids, devices: [{ id, name }] }`, `device_ids` and `devices` in preset order (the host first; a device removed since is left out of `devices`).

- `name` is required on create (max 100, trimmed); `device_ids` needs **at least 2 distinct, existing devices**. Duplicates and unknown ids are `422`.
- **activate** makes every device after the first call `joinSession(host)` on its own driver. A device that cannot join (no multiroom, unreachable, the source cannot be joined) is reported in `failed` and the rest still join: `status` is `ok`, `partial` (some failed) or `failed`.
  - `200` with `status` `ok` or `partial`, `{ host: {id, name}, joined: [{id, name}], failed: [{id, name, message}] }`.
  - `502 driver_error` when nobody joined (same body plus `error`, `message`).
  - `422 invalid` when fewer than 2 of the preset's devices still exist.
- Hardware behaviour of the join is each driver's (see [device-drivers.md](../architecture/device-drivers.md)); some sources cannot join (Spotify to a BeoSound Moment).
