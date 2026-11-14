# Diagnostics bundle (workstream 9b)

One zip a user attaches to an issue. Built by `App\Domain\Diagnostics\DiagnosticsBundle`, run through `App\Domain\Diagnostics\Redactor`.

## Entry points

- `php artisan diagnostics:bundle [--path=file.zip|dir]` (default `storage/app/private/diagnostics/remoment-diagnostics-<time>.zip`).
- `/settings/health` › Diagnostics › download (route `settings.health.diagnostics`, admin session).
- `GET /api/admin/diagnostics` (admin token, `operationId downloadDiagnostics`, in `docs/api/openapi.yaml`). Both web and API use `DiagnosticsController::download`, which writes a temp file and deletes it after sending.

Nothing is uploaded and nothing is kept on the server except what the command writes where asked. No MCP tool.

## Contents

| File | What |
|---|---|
| `README.txt` | exactly what is and is not included |
| `system.json` | app version (+ recorded version), profile, PHP version/extensions, DB driver and server version, pending migrations |
| `heartbeats.json` | scheduler, queue worker and per-queue heartbeats |
| `queues.json` | pending jobs per class, per-queue state (Redis), failed jobs: class, queue, first line of the exception (payload never read) |
| `devices.json` | id, brand, model, driver, state, last seen, listener status, **names** of `device_meta` keys (never values); no name, IP, MAC |
| `alerts.json` | active and the 50 latest resolved alerts |
| `external-services.json` | last cached `ExternalServices` results (never probes on its own); only when `Profile::allows('library')` |
| `upgrade-history.json` | the `upgrade_history` setting |
| `laravel.log` | last 500 lines, redacted |

A section that throws is replaced by `{"unavailable": "..."}`; the rest still ships. Control profile: library sections are skipped and the README says so.

## Redactor

There is no flag to include secrets. Every string written passes `Redactor::text()`; structures pass `value()` (values under secret-looking keys are masked entirely). It removes IPv4/IPv6 and MAC addresses (`[ip]`, `[mac]`), e-mail, `Bearer`/`Basic` credentials, `rmt_` admin tokens, `user:pass@` in URLs, `key=value` / JSON pairs whose key names a secret (password, secret, token, api key, session key, credential, cookie, signature, whole-word `key`/`auth`/`pin`/`sig`/`salt`/`dsn`), JWTs and opaque strings of 32+ characters. `Redactor::forInstall()` also learns literal values: APP_KEY, the DB password, `.env` values of secret-looking keys, the credentials registry's secret settings (`ServiceCredentials::secretSettingKeys()`) and settings keys matching the backup rule (`token|secret|session_key|password`), and removes them wherever they appear, including JSON-escaped and URL-encoded. It is pattern based: new data (a new section) must be added to `DiagnosticsBundle` deliberately and covered by `tests/Feature/DiagnosticsBundleTest.php`, which seeds secrets in settings, the admin user and token, device, meta, alerts, failed jobs, upgrade history and the log and asserts none is in the zip.

## Not done (roadmap item 5 extras)

Per-device probe attachment (`ase:map`, `mlgw:probe`, `wiim:probe`), log rotation caps, and an opt-in to keep device names. Library contents are excluded entirely.
