# Alerts (workstream 14.1)

One place raises and resolves alerts; everything else reads from it. An **alert** is a condition with a state (raised, later resolved) and a dedupe key `(kind, key)`, so a condition that persists does not repeat every minute. Playback events (a track started) are not alerts and stay on `remoment/player/{id}/*`.

Code: `App\Domain\Alerts\Alerts` (the API), `AlertChecks` (the checks), `App\Models\Alert`, the `alerts` table (one migration; the schema review 14.3 consolidates it), `php artisan alerts:check`, thresholds in `config/alerts.php`.

## Model

`id` (monotonic), `kind` (dotted), `key` (dedupe key within the kind: a queue name, a device or location id, `''` for a single condition), `severity` (`info` | `warning` | `critical`), `device_id` (nullable), `title`, `message`, `data` (JSON), `raised_at`, `resolved_at`, `acknowledged_at`.

- **One active alert per `(kind, key)`**, enforced by a unique nullable `active_key` column (`kind|key` while active, `NULL` once resolved), which works on MariaDB and SQLite alike.
- `Alerts::raise($kind, $key, $severity, $title, $message, ?$deviceId, ?$data)` is idempotent: an active alert is updated, not duplicated, and is **re-published only when severity or message changed**.
- `Alerts::resolve($kind, $key)` closes it and publishes `resolved`. Raising the same condition later creates a **new** alert with a new id.
- Acknowledge (`Alerts::acknowledge`) only stamps `acknowledged_at`; it is admin-wide, not per user, and does not resolve.
- Reads: `active()`, `recent($limit)`, `since($id, $state, $limit)`, `summary()`.
- Nightly `Alerts::prune()` (04:45) keeps every active alert and the last 100 resolved.
- Nothing in `Alerts` throws: a failure (DB, MQTT) is logged and swallowed, so alert code can never break the thing being checked.

## Kinds

| Kind | Key | Severity | Raised by | Raises when / resolves when |
|------|-----|----------|-----------|-----------------------------|
| `queue.stuck` | queue name | critical | `AlertChecks::queues` | the queue's runner state (`QueueInspector::runnerState`) is `stuck`; `ok` resolves. `busy` and `unknown` never raise and leave an active alert as it is. Redis driver only |
| `scheduler.silent` | | critical | `AlertChecks::scheduler` | the scheduler heartbeat is missing or 180 s old; resolves on a fresh beat. Also checked by the open `/settings/health` page, since a dead scheduler cannot run `alerts:check` |
| `backup.stale` | | warning | `AlertChecks::backup` | `BackupStatus::health()` is not ok (no backup, or older than `backup.stale_hours` = 48 h) |
| `storage.unreachable` | location id | critical | `AlertChecks::storage` | the storage probe of `/settings/health` fails (probed every 5 min; a deleted location resolves) |
| `device.offline` | device id | warning | `AlertChecks::devices` | cached state `unreachable` for 10 minutes in a row (`ALERTS_DEVICE_OFFLINE_MINUTES`); standby never raises |
| `listener.down` | device id | warning | `AlertChecks::devices` | a device whose driver has a listener (`DeviceListeners::for()` not null) has no `listener_running_{id}` heartbeat for 3 minutes (`ALERTS_LISTENER_DOWN_MINUTES`) |

Reserved for later: `container.crashed` and `disk.low` (the Manager, workstream 10).

**Debounce** (device kinds): the condition must hold continuously for its threshold before it raises (a listener restart or a short outage never raises), and must be gone for `resolve_after_minutes` (2) before it resolves, so a flapping device does not raise and resolve in a loop. The state is two cache keys per condition (`alerts:{kind}:{key}:bad-since` / `good-since`).

`alerts:check` runs every minute from the scheduler (`routes/console.php`), reads caches and cheap probes only, and runs each check in its own try/catch.

## Outputs

- **MQTT** `remoment/alerts/{kind}`, not retained, on raise, on a changed severity/message and on resolve: `{ id, kind, key, severity, title, message, device_id, raised_at, state: "raised"|"resolved" }`.
- **MQTT** `remoment/alerts/active`, retained, only when it changes (dedupe key `mqtt_published_alerts_active`): `{ counts: { info, warning, critical }, kinds: [...], total }`.
- **REST** `GET /api/alerts?since=&state=active|resolved|all&limit=` (open, newest first, `since` = id cursor, default `all`, limit 1–200 default 100) → `{ data: [Alert], summary }`. An alert keeps its id when it resolves, so a client polling with `since` sees new alerts only; re-read `state=active` to learn which ended. `POST /api/admin/alerts/{id}/ack` (admin token) → `{ data: Alert }`. Contract in `docs/api/openapi.yaml`.
- **Web** the active alerts at the top of `/settings/health` (the backup one keeps its own notice); a signed-in admin gets an Acknowledge button on each.
- **MCP** the `alerts` tool: `list` (open) and `ack` (admin).
- `GET /api/info` has no `features` list yet, so no `alerts` feature key was added; add it when that list exists.

## Add a kind

1. Pick `kind` and `key`; call `Alerts::raise(...)` when the condition starts and `Alerts::resolve(...)` when it ends (from a check in `AlertChecks`, or from the code that knows).
2. Debounce flapping conditions with `AlertChecks::sustained`.
3. Add a row to the table above and a test that raises and resolves it.

## Consumers

8.6 browser notifications (subscribe to the MQTT topic), 8a Home Assistant (event entities on `remoment/alerts/{kind}`), 8b item 5 (the app's alerts feed is `GET /api/alerts`), the Manager of workstream 10 (raises `container.crashed` and `disk.low` through the same API).

## Not built here

Browser notifications and the PWA, Home Assistant discovery, Web Push, the Manager, per-user anything (acknowledge is admin-wide). No `features.alerts` key. `scheduler.silent` with a completely dead scheduler is only noticed while the health page is open or by something subscribing to the retained summary and watching its age; a watcher outside the app is the Manager's job.
