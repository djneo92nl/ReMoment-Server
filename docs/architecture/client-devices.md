# Client Device Management — Architecture

How the server manages client device registrations, tokens, and device assignments.

---

## Overview

Client devices (ESP8266, ESP32, Raspberry Pi, software integrations) register with the server via a two-step flow: self-registration followed by admin approval. The server tracks firmware metadata and controls which media players each client can see. MQTT topics remain open and unauthenticated — this system is a convenience layer for device discovery and assignment, not a security gate.

---

## Database

### `clients` table

`app/Models/Client.php` — one row per registered client.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | bigint PK | | |
| `name` | string nullable | null | Admin-assigned label; clients that haven't been named show as "Client #id" |
| `type` | enum `single\|multi` | `single` | `single`: one assigned device; `multi`: several or all |
| `status` | enum `pending\|approved` | `pending` | Pending until admin approves |
| `hardware_id` | string(100) nullable unique | null | MAC / chip ID; enables idempotent re-registration |
| `registration_token` | string(64) unique | — | Generated on first registration; returned to client immediately |
| `pairing_code` | string(8) nullable unique | null | 6-char human-readable code shown on the client screen and in the admin UI; generated on registration (backfilled by migration for older rows) |
| `api_token` | string(64) nullable unique | null | Generated on approval; null until then |
| `ip_address` | string(45) nullable | null | Updated from request IP on register and heartbeat |
| `firmware_version` | string(50) nullable | null | |
| `build_number` | unsigned int nullable | null | |
| `metadata` | json nullable | null | Arbitrary client-supplied key/value (board type, notes, etc.) |
| `last_seen_at` | timestamp nullable | null | Updated on `GET /devices` and heartbeat |
| `approved_at` | timestamp nullable | null | Set when admin approves |
| `created_at` / `updated_at` | timestamps | | |

### `client_device` pivot table

Links clients to their allowed devices. No timestamps.

| Column | Type | Notes |
|--------|------|-------|
| `client_id` | bigint FK → clients | `ON DELETE CASCADE` |
| `device_id` | bigint FK → devices | `ON DELETE CASCADE` |
| `sort_order` | unsigned smallint | Display order on client; default 0 |

Primary key is `(client_id, device_id)`.

---

## Model: `app/Models/Client.php`

```php
protected $fillable = [
    'name', 'type', 'status', 'hardware_id',
    'registration_token', 'pairing_code', 'api_token',
    'ip_address', 'firmware_version', 'build_number',
    'metadata', 'last_seen_at', 'approved_at',
];

protected $casts = [
    'metadata' => 'array',
    'last_seen_at' => 'datetime',
    'approved_at' => 'datetime',
];
```

**`devices()` relationship:**

```php
return $this->belongsToMany(Device::class)
    ->withPivot('sort_order')
    ->orderByPivot('sort_order');
```

**`Client::generateToken(): string`** — returns `Str::random(48)`. Used for both `registration_token` (on create) and `api_token` (on approval).

**`Client::generatePairingCode(): string`** — returns `PAIRING_CODE_LENGTH` (6) random characters from `PAIRING_CODE_ALPHABET` (`ABCDEFGHJKLMNPQRSTUVWXYZ23456789` — no `0`/`O`/`1`/`I`), retrying until no other client has it. 32⁶ ≈ 10⁹ combinations, so collisions are practically nonexistent; the retry loop plus the unique index guarantee uniqueness anyway.

**DB default gotcha:** `status` defaults to `'pending'` at the database level, but the Eloquent model instance returned by `Client::create()` does not automatically reload DB-generated defaults. Always pass `'status' => 'pending'` explicitly to `create()`.

---

## API Controller: `app/Http/Controllers/Api/ClientController.php`

Four public methods, all unauthenticated.

### `register(Request $request): JsonResponse`

1. Validates optional fields: `hardware_id`, `firmware_version`, `build_number`, `metadata`
2. If `hardware_id` provided and a matching `clients` row exists: updates that row (IP + firmware; assigns a `pairing_code` if the row predates them), returns existing `registration_token` + `pairing_code` with HTTP 200
3. Otherwise: creates new `Client` with `status = 'pending'` (set explicitly, not relying on DB default), a new token and a new pairing code, returns HTTP 201

Response: `{ registration_token, pairing_code, status }`

### `status(string $registrationToken): JsonResponse`

1. Looks up client by `registration_token`; 404 if missing
2. If `status = 'pending'`: returns `{ status: 'pending' }`
3. If `status = 'approved'`: calls `resolveDevices()` and returns `{ status, type, api_token, devices }`

### `devices(string $apiToken): JsonResponse`

1. Looks up client by `api_token`; 404 if missing
2. Updates `last_seen_at`
3. Calls `resolveDevices()` and returns device list

### `heartbeat(Request $request, string $apiToken): JsonResponse`

1. Looks up client by `api_token`; 404 if missing
2. Updates `ip_address` (from `$request->ip()`), optional `firmware_version` / `build_number`, and `last_seen_at`

### `artwork(Request $request, string $apiToken): JsonResponse`

1. Looks up client by `api_token`; 404 if missing (does not touch `last_seen_at`)
2. Takes `ARTWORK_PAGE_SIZE` (200) albums of `LibraryArtwork::albumsByRecency()` (played albums by last play, newest first, then the rest by id) from offset `cursor`
3. Takes each album's cover (`images[0]`, string or `{url}`), dedupes, and reads their `ArtworkCache` entries in one `Cache::many()`; `LibraryArtwork::clientFiles()` takes `proxy_320`/`proxy_120`/`proxy_bg`/`proxy_bg_320x480` (`LibraryArtwork::CLIENT_FILES`) from the entry, else from the files on the public disk (expired entry), else skips the cover
4. On the first page (`cursor` 0) prepends the eight `SourceLogo` items (`NowPlayingArtwork::logo()` renders missing ones)
5. Returns `{ data: [{kind, hash, proxy_320, proxy_120, proxy_bg, proxy_bg_320x480}], next_cursor }`; `next_cursor` is `cursor + 200` when the page was full

It never queues processing; `artwork:prerender` and `library:backfill-artwork` (both daily) do.

### `resolveDevices(Client $client)` (private)

Device resolution rules:

| Client type | Pivot assignments | Returns |
|-------------|-------------------|---------|
| `single` | any | `$client->devices` (0 or 1 row) |
| `multi` | at least one | `$client->devices` ordered by `sort_order` |
| `multi` | none | `Device::orderBy('device_name')->get()` — all devices |

Devices are returned as `DeviceListResource` collection (same shape as `GET /api/devices`).

---

## API Routes

Defined in `routes/api.php`, no middleware:

```
POST   /api/clients/register
GET    /api/clients/status/{registrationToken}
GET    /api/clients/{apiToken}/devices
PUT    /api/clients/{apiToken}/heartbeat
GET    /api/clients/{apiToken}/artwork
```

---

## Admin: Livewire Component

`app/Livewire/ClientManager.php` + `resources/views/livewire/client-manager.blade.php`

### State

| Property | Type | Purpose |
|----------|------|---------|
| `$approvingId` | `?int` | Client ID whose approve form is open |
| `$approveName` | `string` | Name input in the approve form |
| `$approveType` | `string` | Type selector in the approve form (`single`\|`multi`) |
| `$editing` | `?int` | Client ID whose edit form is open |
| `$editName` | `string` | Name input in the edit form |
| `$editType` | `string` | Type selector in the edit form |
| `$editDeviceIds` | `array<int>` | Checked device IDs in the edit form |

Only one form is open at a time. Opening a new approve/edit form does not close the other automatically — the view only renders the relevant form per client row.

### Actions

**`startApprove(int $clientId)`** — opens the approve form for a pending client; resets `approveName` and `approveType`.

**`approve()`** — validates `approveType`; sets `status = 'approved'`, `api_token = Client::generateToken()`, `approved_at = now()`, and optionally `name` and `type`; closes the form.

**`reject(int $clientId)`** — deletes the pending `Client` row entirely (cascades to pivot).

**`startEdit(int $clientId)`** — loads the client with its devices; populates edit properties; opens the edit form.

**`saveEdit()`** — validates `editType` and `editDeviceIds`; if `type = 'single'` and more than one device ID is selected, truncates to the first; calls `$client->devices()->sync($deviceIds)` for the pivot; closes form.

**`regenerateToken(int $clientId)`** — issues a new `api_token` via `Client::generateToken()`; old token stops working immediately.

**`delete(int $clientId)`** — deletes the client; pivot rows cascade.

### View structure

The view renders two logical sections:

1. **Pending registrations** — amber-highlighted block, only shown when at least one pending client exists. Each row leads with the `pairing_code` in a large monospace badge (the admin matches it against the code on the client's screen), followed by IP, hardware_id, firmware, build, metadata, and registration time. Approve opens an inline form (name + type selector). Reject requires a confirmation.

2. **Approved clients** — white card, one row per approved client. Shows: type badge (`single`/`multi`), name, IP, firmware, build, last seen, assigned device chips, truncated `api_token` with an Alpine.js copy-to-clipboard button, edit / regenerate / delete actions.

   Clicking Edit replaces the row content with an inline form: name input, type select, device checkboxes (all devices listed, checked ones highlighted). Warning shown if `single` type but more than one device is checked.

3. **API reference card** — static summary of the four client API endpoints, shown at the bottom of the page for reference.

---

## Settings Integration

### Controller: `SettingsController::clients()`

```php
public function clients()
{
    $clientCount = Client::count();
    $pendingCount = Client::where('status', 'pending')->count();

    return view('settings.clients', compact('clientCount', 'pendingCount'));
}
```

The controller only passes counts; all interactive state lives in the Livewire component.

### Route

```
GET /settings/clients   → settings.clients   (auth middleware)
```

Defined in `routes/web.php` inside the `auth` middleware group.

### View: `resources/views/settings/clients.blade.php`

Shell template using `x-app-layout`. Passes `$clientCount` and `$pendingCount` into the header subtitle. Mounts `<livewire:client-manager />` in a `max-w-4xl` container.

### Settings index card

`resources/views/settings/index.blade.php` contains a card linking to `/settings/clients`. It runs its own inline `@php` queries for counts so the `SettingsController::index()` method does not need to know about clients. When `$pendingCount > 0` the count is shown in amber to draw attention.

---

## Token Design

Both `registration_token` and `api_token` are 48-character random strings (`Str::random(48)`). They are stored plaintext — there is no hashing. The system is designed for a trusted local network, not public internet exposure.

`pairing_code` is deliberately short and not secret: it only helps the admin tell pending registrations apart. It grants nothing — approval still happens in the admin UI, and the client keeps polling with its `registration_token`.

`registration_token` is issued immediately and never changes. It is safe to re-send on every boot (re-registration with a known `hardware_id` returns the same token).

`api_token` is null until the admin approves the client, then is set once. It can be rotated by the admin via "Regenerate token" — the old token becomes invalid immediately with no grace period.
