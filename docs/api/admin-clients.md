# Admin clients (`/api/admin/clients`)

Client registrations for apps: the actions of **Settings › Clients**, behind the admin token (`401 unauthenticated` without one). Web and API call one class, `App\Domain\Clients\ClientAdmin`. The client's own side (register, poll status, heartbeat) is [client-devices.md](client-devices.md); a pairing code on the client's screen matches the `pairing_code` here. MCP: tool `clients`.

```
GET    /api/admin/clients?status=pending|approved     → { data: [Client], pending }   pending first, then newest first
GET    /api/admin/clients/{id}                        → { data: Client + api_token }
POST   /api/admin/clients/{id}/approve                { type?, name?, roles? }   → { data: Client + api_token }
POST   /api/admin/clients/{id}/reject                 → { status: "ok" }          a pending registration is removed
PUT    /api/admin/clients/{id}                        { name?, type?, device_ids?, roles?, hidden_sources? }   → { data: Client }
POST   /api/admin/clients/{id}/regenerate-token       → { data: Client + api_token }
DELETE /api/admin/clients/{id}                        → { status: "ok" }
```

## Client

```json
{
  "id": 4, "name": "Hall panel", "kind": "client", "type": "single", "status": "approved",
  "pairing_code": null, "hardware_id": "esp32-aa11", "ip_address": "192.168.1.60",
  "firmware_version": "1.2.0", "build_number": "34", "battery": { "level": 87, "charging": false },
  "last_seen_at": "…", "approved_at": "…", "created_at": "…",
  "devices": [ { "id": 1, "name": "Living Room" } ],
  "hidden_sources": ["dlna"], "roles": [], "enabled_roles": []
}
```

- `kind` is `client` or `node`; a node declares `roles` (`ripper`, `line-in`, `player`, `sync-target`) and the admin switches some on (`enabled_roles`, always within what it declared).
- `pairing_code` is only set while the registration is pending. `battery` is `null` for a client without one.
- The **API token is never in the list**. It is in the detail, and in the answers of approve and regenerate-token, because those are the moments an admin needs it.

## Rules

- **approve** needs a pending registration (`409 not_pending`). `type` is `single` (default) or `multi`; `name` is trimmed, empty means none. `roles` lists the node roles to switch on (default none; the web form ticks every declared role first). The token is issued now; the client collects it from its status poll.
- **reject** needs a pending registration (`409 not_pending`); an approved client is removed with DELETE.
- **update** changes only the keys sent. `device_ids` replaces the list (existing devices only, `422` otherwise); a `single` client keeps at most one (the first). `hidden_sources` replaces the list of library ids the client does not see (see `GET /api/library/libraries`, plus `radio`). `roles` only means something for a node.
- **regenerate-token** needs an approved client (`409 not_approved`); the old token stops working at once.
- Unknown ids are `404`; invalid values `422` (Laravel validation shape).
