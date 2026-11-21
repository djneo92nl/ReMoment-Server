# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ReMoment Server is a Laravel 12 application that acts as a universal controller and abstraction layer for networked audio/video devices (Bang & Olufsen ASE, Sonos, Spotify). It normalizes control interfaces across brands, captures playback history, publishes events to MQTT for IoT integration, and exposes a REST API for client devices.

## Auth Model

There is exactly **one shared admin login** (seeded by `DatabaseSeeder` as `admin@admin.com`) — there is no self-registration route, no multiple accounts, and no per-user profiles or roles. This one login gates only admin setup/config actions: `/settings/*`, the Spotify/Last.fm OAuth connect flows, and client device approval. Every other page — `/devices`, `/receiver`, `/history`, `/library`, `/stats`, playback controls, etc. — is intentionally guest-accessible with no login required, matching the REST API (also unauthenticated). Apps that can't use the session cookie sign in with that same password at `POST /api/admin/login` and get a bearer token (one hashed row per app install in `admin_tokens`, revocable under Settings › Users, all revoked when the password changes; see `docs/api/admin-auth.md`). Admin API routes go inside `Route::middleware('admin.token')`. A token is not an account. **Never add user registration, multiple accounts, profiles, or role-based access** — this is a deliberate, permanent constraint, not an oversight to "fix."

## Documentation

Detailed docs live in `docs/`; CLAUDE.md only holds what is needed to orient. **Read the relevant doc before implementing or changing a feature.**

- `docs/INDEX.md` — one-line summary of every doc (find the right one here)
- `docs/api/` REST endpoints, `docs/architecture/` server-side systems, `docs/frontend/` UI pages, `docs/user/` end-user docs, `docs/api/openapi.yaml` the client contract
- `docs/reference/rest-api.md` — detailed REST API reference (device list/detail, controls, library, queue, radio, multiroom, scenes, clients …)
- `docs/reference/mqtt.md` — MQTT topics and payloads
- `docs/reference/architecture.md` — event-driven core, driver pattern, device cache keys, artwork pipeline, library identity, Spotify routing/mapping, multiroom, nodes
- `docs/reference/frontend-infra.md` — Livewire components, web pages, controllers, Docker infrastructure
- `docs/reference/commands.md` — every `php artisan` command with flags

When adding a significant feature, add a matching doc in the relevant subfolder (`api/` for new REST endpoints, `architecture/` for server-side systems, `frontend/` for complex UI pages) and a line in `docs/INDEX.md`. When an endpoint's shape changes, update `docs/api/openapi.yaml`.

## MCP Server

`/mcp` (`app/Mcp/`, `laravel/mcp`, `routes/ai.php`) lets an AI agent control devices and manage the library. Open like the REST API; the tools marked ADMIN (maintenance, removal, storage) are listed only with the admin bearer token (no new accounts). Device/library tools go through `App\Mcp\ApiBridge`, an in-process call of the REST API, so the API stays the single implementation: when you add an endpoint, add or extend a tool in `app/Mcp/Tools/` and the table in `docs/api/mcp.md`. Playlist create/edit/delete lives in `App\Domain\Library\PlaylistEditor` (web UI and MCP share it). The analytics reads have tools too (`library_overview`, `library_health`, `listening_stats`, `listening_insights`, sound filters on `browse_library` kind `tracks`): open, with the profile gates of their REST routes coming through the bridge. See `docs/api/mcp.md`.

## Profiles

`PROFILE=control|standard|full` in `.env` (default `standard`) decides what runs; read it only through `App\Domain\Profile\Profile` (`allows('library')`), gate tables in `config/profile.php`. Control (Pi, device control, SQLite in a named volume) has no library, catalogue or analysis: scheduled tasks use `->when(fn () => Profile::allows(...))`, supervisor programs read `START_*` from `docker/8.4/profile-env.sh`, gated URLs answer `422 not_available_in_profile` (API) or redirect to `/not-in-profile` (web), `GET /api/info` has `features.profile`. A new scheduled task, worker, route group or nav entry of a feature must be gated (a test fails for an ungated task). See `docs/architecture/profiles.md`.

## Core conventions

- **Drivers**: `config/devices.php` maps brands/models to driver classes implementing contracts in `app/Integrations/Contracts/`. Read models via `App\Domain\Device\DeviceModels`, never by iterating `config('devices')`. Capabilities come from `Domain\Device\DeviceCapabilities::for()`; always route playback commands through `DeviceRouting` (sub devices, Spotify routing), not the device's own driver. Check `capabilities` before a feature endpoint (unsupported → `422`). See `docs/architecture/device-drivers.md`.
- **Events**: listeners are registered only in `app/Providers/AppServiceProvider.php` (auto-discovery is disabled; it registered everything twice). Device state lives in Redis via `DeviceCache`. See `docs/reference/architecture.md`.
- **Library**: one record per artist/album/track across sources (`LibraryIdentity`); the library is the local own files, streaming (Spotify) extends it live and is never imported; DLNA is never "local". See `docs/architecture/library-identity.md`.
- **MCP**: when adding a REST endpoint, add or extend a tool in `app/Mcp/Tools/` and the table in `docs/api/mcp.md`; tools go through `ApiBridge`.
- **Profiles**: gate new scheduled tasks, workers, route groups and nav entries (a test fails for an ungated task).
- **Artwork**: the web UI never shows an original image URL, use `LibraryItemArtwork::proxy()` / `<x-artwork-thumb>`; JPEGs must be baseline (ESP32). See `docs/reference/architecture.md`.
- **Frontend**: Blade + Livewire 3 + Alpine, Tailwind compiled by Vite; no JS framework (JS only as thin glue triggering `$wire.$refresh()`). **Read `DESIGN.md` before changing any view.**
- **Hardware**: much driver behaviour is unverified on real hardware; say so in docs when it is.

## Common Commands

```bash
composer setup            # initial setup
composer dev              # Laravel, queue, Vite, log viewer
composer test             # all tests (SQLite)
php artisan test --filter=TestClassName
./vendor/bin/pint         # code style, run before committing
php artisan device:discover [--add]      # discover devices of every brand
php artisan device:listen                # device listeners (forks one per device)
php artisan library:scan-files           # index own audio files
php artisan library:enrich | library:analyze | library:merge-duplicates --dry-run
php artisan app:upgrade [--check] | backup:run | diagnostics:bundle
```

Everything else (flags, scheduled jobs, probes, Home Assistant, Web Push, exports …): `docs/reference/commands.md`.

## Tests

`composer test` runs everything on SQLite. Fixtures to reuse instead of writing the attribute list again: `Device::factory()` (a device driven by `Tests\Support\FakePlayerDriver`; `->driver($class)`, `->inState(State::Playing)`, or override any attribute), and the traits in `tests/Support`: `CachesArtwork` (a processed cover's cache entry), `FakesSpotify` (`connectSpotify($api = null)` / `disconnectSpotify()`) and `MakesLibraryTracks` (a track playable from DLNA and/or Spotify). The `Fake*Driver` classes there each implement just the contracts one test area needs.

## Code Style

Laravel Pint with the `laravel` preset (`pint.json`). Run `./vendor/bin/pint` before committing.
