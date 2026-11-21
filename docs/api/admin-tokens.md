# Admin tokens (`/api/admin/tokens`)

The app sign-ins of **Settings › Admin & Apps**, behind the admin token (`401 unauthenticated` without one). Web and API share `App\Domain\Admin\AdminTokens`. A token is one row per app install, not an account: there is still exactly one admin ([admin-auth.md](admin-auth.md)). MCP: tool `admin_tokens`.

```
GET    /api/admin/tokens                     → { data: [Token] }          most recently used first
DELETE /api/admin/tokens/{id}                → { status: "ok" }           that app has to sign in again
DELETE /api/admin/tokens?keep_current=1      → { status: "ok", revoked }  every token; keep_current spares the caller's own
```

```json
{ "id": 3, "name": "Remko's iPhone", "last_used_at": "…", "last_used_ip": "192.168.1.40", "created_at": "…", "current": true }
```

- `current` is true for the token that made the request, so the app can label "this device" and warn before signing itself out.
- The stored hash is never returned (the model hides it); the plain token is only ever shown once, at sign-in.
- Revoking the calling token is allowed and equals `DELETE /api/admin/token`. `DELETE /api/admin/tokens` without `keep_current` signs the caller out too.
- `last_used_at` and the IP are updated at most once a minute.
- An unknown id is `404`.

**Not here on purpose:** changing the admin password stays on the web (Profile). It signs every app out (`revokeAll()`), which is the same code as "revoke all" above.
