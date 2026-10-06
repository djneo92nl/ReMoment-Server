# Admin token login (`/api/admin/*`)

The REST API is open: playback, library, devices and client registration need no login. Configuration is not: the web UI keeps `/settings/*`, the Spotify/Last.fm connect flows and client approval behind the admin login (a session cookie). An app can't use that cookie conveniently, so it signs in once and keeps a **bearer token**.

This is still the single shared admin (see CLAUDE.md, *Auth Model*): the login is password-only against the one user, there is no email/username, and a token is **not an account**. It is one row per app install in `admin_tokens`, so a lost phone can be signed out without touching the others. Code: `Api/AdminAuthController`, `Http/Middleware/AuthenticateAdminToken` (alias `admin.token`), `Models/AdminToken`.

---

## Sign in

```
POST /api/admin/login      body: { "password": "…", "device_name": "Remko's iPhone" }
  → 200 { "token": "rmt_…", "token_id": 3, "name": "Remko's iPhone" }
```

`device_name` is optional (max 100 characters) and only labels the sign-in under **Settings › Users › Signed-in apps**. The plain `token` is returned **once**; the server keeps only its SHA-256, so it can't be shown again. Store it in the Keychain (or the platform's equivalent), never in plain preferences.

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
- The admin signs any app out at **Settings › Users › Signed-in apps** (`DELETE /settings/app-tokens/{id}`).
- **Changing the admin password signs every app out** (`PasswordController::update` deletes all tokens).

Tokens do not expire on their own.

## What it gates, and what it does not

Only routes inside the `admin.token` group. Today that is the three routes above. The device-settings endpoints (`/api/devices/{id}/sound-adjustment`, `bluetooth`, `network`, `info`, …) are **unchanged and still open**, as is everything else documented in `docs/api/`; moving the admin-only ones (network and Wi-Fi changes, renaming) behind the token is a separate, breaking decision for the ESP clients. Admin endpoints that only exist in the web UI today (source hide/reorder, multiroom presets, Spotify Connect mapping, DLNA scans, client approval) will be added inside the group.

Brute force: the limiter is per IP and in the cache (Redis), like the web login's.
