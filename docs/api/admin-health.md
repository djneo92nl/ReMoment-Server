# Admin health (`/api/admin/health`)

The data of **Settings › Health** for apps, behind the admin token (see [admin-auth.md](admin-auth.md); `401 unauthenticated` without one). Web and API read the same class, `App\Domain\Health\HealthSummary` (and `ExternalServices` for the probes), so the two never disagree. Alerts and their acknowledgement are separate (`GET /api/alerts`, `POST /api/admin/alerts/{id}/ack`); the active ones are repeated in the summary. MCP: tool `server_health`.

```
GET  /api/admin/health                  → the summary below
GET  /api/admin/health/services         → the cached probe results of the external services
POST /api/admin/health/services/check   { only?: ["musicbrainz", …] }   → the same, after running the probes now
```

## Summary

```json
{
  "ok": false,
  "version": "1.0.0-dev",
  "broker": { "ok": true, "address": "mosquitto:1883", "error": null, "ws_url": "ws://192.168.1.100:9001" },
  "supervisor": { "running": true },
  "scheduler": { "ok": true, "last_beat_at": "2026-11-21T12:00:01+00:00" },
  "queue_worker": { "ok": true, "last_beat_at": "2026-11-21T12:00:03+00:00" },
  "queue": { "pending": 0, "failed": 0 },
  "backup": { "ok": true, "last_at": "2026-11-21T03:00:12+00:00" },
  "upgrade": { "from": "0.9.0", "to": "1.0.0-dev", "at": "…", "result": "ok", "backup": "…" },
  "radio_relay": { "enabled": false, "manager": false, "max": 3, "data": [] },
  "alerts": { "active": 1, "warning_or_critical": 1, "data": [ /* Alert, as GET /api/alerts */ ] },
  "devices": [
    { "id": 1, "name": "Living Room", "state": "playing", "last_seen": "…", "volume": 35,
      "listener": { "running": true, "state": "running", "since": "…", "failures": 0, "last_error": null, "retry_at": null } }
  ],
  "ip_changes": [ { "device_id": 1, "name": "Living Room", "from": "192.168.1.10", "to": "192.168.1.12", "at": "…" } ]
}
```

- `ok` is the one flag for a badge: the broker answers, the scheduler and the queue worker beat within 3 minutes, the last backup is younger than `backup.stale_hours` (48 hours), and no active alert is `warning` or `critical`.
- `supervisor.running` is `null` where it cannot be told (not Linux, or not in the container). `queue.pending` / `failed` are `null` when the queue or the table cannot be read.
- `upgrade` is the last entry of `upgrade_history` or `null`. `devices[].volume`, `last_seen` and the listener fields are what the listener last cached (never a call to the device); a device nobody has listened to is `volume: null`.
- Reading the summary also lets a *silent scheduler* raise its alert, which is why an alert can appear right after a read.

## External services

```json
{ "checked_at": "2026-11-21T11:45:00+00:00", "problems": 1,
  "data": [ { "id": "musicbrainz", "name": "MusicBrainz", "group": "Metadata", "status": "ok", "message": "Working" } ] }
```

`status` is `ok`, `warning` (rate limited, a scope missing, a credential half set up), `error` or `disabled` (not configured, nothing to check). Secrets are redacted from `message`. `GET …/services` only reads the cache (15 minutes, `data: []` and `checked_at: null` before the first check); `POST …/check` runs the probes (each has an 8 second timeout, so it can take a while) and answers with the fresh list. `only` takes probe ids from the list; an unknown id is `422`.

## Not here yet

Analysis status and its actions (run the backlog, retry a stage), the queue inspector (pending per class, failed jobs, retry, forget) and a listener restart are still web-only; the library health reports have their own open routes (`GET /api/library/health`).
