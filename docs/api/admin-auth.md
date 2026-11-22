# Admin token login (`/api/admin/*`)

The REST API is open: playback, library, devices and client registration need no login. Configuration is not: the web UI keeps `/settings/*`, the Spotify/Last.fm connect flows and client approval behind the admin login (a session cookie). An app can't use that cookie conveniently, so it signs in once and keeps a **bearer token**.

This is still the single shared admin (see CLAUDE.md, *Auth Model*): the login is password-only against the one user, there is no email/username, and a token is **not an account**. It is one row per app install in `admin_tokens`, so a lost phone can be signed out without touching the others. Code: `Api/AdminAuthController`, `Http/Middleware/AuthenticateAdminToken` (alias `admin.token`), `Models/AdminToken`.

---

## Sign in

```
POST /api/admin/login      body: { "password": "…", "device_name": "Remko's iPhone" }
  → 200 { "token": "rmt_…", "token_id": 3, "name": "Remko's iPhone" }
```

`device_name` is optional (max 100 characters) and only labels the sign-in under **Settings › Admin & Apps**. The plain `token` is returned **once**; the server keeps only its SHA-256, so it can't be shown again. Store it in the Keychain (or the platform's equivalent), never in plain preferences.

| Status | `error` | When |
|--------|---------|------|
| `401` | `invalid_credentials` | wrong password, or no admin user exists yet |
| `422` | (Laravel validation) | `password` missing |
| `429` | `too_many_attempts` | 5 wrong passwords from one IP within a minute; `retry_after` (seconds) and a `Retry-After` header. Even the right password waits. A successful sign-in clears the count |

## Use the token

```
Authorization: Bearer rmt_…
```

Routes behind `Route::middleware('admin.token')` (see `routes/api.php`) return `401` without a valid token:

```json
{ "error": "unauthenticated", "message": "A valid admin token is required." }
```

with `WWW-Authenticate: Bearer`. On a `401` the app should drop the stored token and show its sign-in again.

```
GET    /api/admin/me       → { "authenticated": true, "token_id": 3, "name": "…", "last_used_at": "…" }   (check a stored token at launch)
DELETE /api/admin/token    → { "status": "ok" }                                                          (sign out: revokes this token)
```

`last_used_at` / the IP are updated at most once a minute.

## Revoking

- The app signs itself out with `DELETE /api/admin/token`.
- The admin signs any app out at **Settings › Admin & Apps** (`DELETE /settings/app-tokens/{id}`), or an app lists and revokes tokens through [`/api/admin/tokens`](admin-tokens.md) (same code, `AdminTokens`).
- **Changing the admin password signs every app out** (`PasswordController::update` deletes all tokens).

Tokens do not expire on their own.

## What it gates, and what it does not

Only routes inside the `admin.token` group (`routes/api.php`): the three routes above, `PUT /api/admin/devices/{id}/sources/{source_id}` (an alias of the open `PUT /api/devices/{id}/sources/{source_id}`, kept for the app), the storage routes and the areas listed below. The device **network** endpoints (`GET /api/devices/{id}/network`, `PUT …/network/interface|wired|wifi`) are in the group too ([device-settings.md](device-settings.md)); the other device-settings endpoints (`sound-adjustment`, `bluetooth`, `info` with renaming, …) are **unchanged and still open**, as is everything else documented in `docs/api/`. Admin areas added inside the group for apps (workstream 8b), each extracted from its web component into a shared domain class: [health](admin-health.md), [clients](admin-clients.md), [radio stations](admin-radio.md) and [devices with the discovery inbox](admin-devices.md); queues are in [admin-queues.md](admin-queues.md). **Multiroom presets are not here on purpose**: they are [open](multiroom-presets.md) like multiroom itself (decided 2026-10-26; the web page has no login either). Spotify and Last.fm connect flows and the Spotify Connect name mapping stay on the web. [Tokens](admin-tokens.md) list and revoke, [analysis](admin-analysis.md) and [library settings and maintenance](admin-library.md) are there too.

Brute force: the limiter is per IP and in the cache (Redis), like the web login's.

## The rule for device settings (owner, 2026-11-22)

Device settings are public, except what can break how the server connects to the device. So open: sound adjustment, Bluetooth, sources (activate, show/hide, reorder), rename, speaker types, sleep timer, power, volume and everything playback. Admin token: the **network** settings (`GET /api/devices/{id}/network`, `PUT …/network/interface|wired|wifi`), adding, editing (address, model) and deleting a device (`/api/admin/devices`), and the discovery inbox. No known client changes the network settings (the iOS app only shows them to the admin; no ESP firmware does), so the network gate breaks nothing today. Two settings sit on the line and are admin today, to be confirmed: **volume limits** (`PUT /api/admin/devices/{id}/volume-limits`, a household safety setting, the web card is admin-only too) and **sub device links** (`/api/admin/device-links`, changes routing, not the connection).
