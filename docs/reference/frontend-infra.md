# Frontend, web pages and infrastructure

Defined in `compose.yaml` via Laravel Sail:
- **laravel.test** – PHP 8.4 app server on FrankenPHP like production (ports 80 and `APP_PORT_ALT`; `FRANKENPHP_MODE=worker` opts into Octane, see `docs/architecture/frankenphp.md`)
- **mariadb** – the database (named volume, not a bind mount; see `docs/architecture/database.md`)
- **redis** – cache, sessions, and queue backend
- **mosquitto** – MQTT broker (port 1883; WebSockets on 9001 for browser live updates)
- **meilisearch** – search (port 7700)
- **Vite dev server** – port 5173

Client devices find the server via mDNS (`_remoment._tcp`, TXT `path=/api`, `api_version`), advertised by Avahi on the Docker **host** — set up by `ansible/setup.yml`, not inside a container. See `docs/architecture/discovery-for-clients.md`.

---

## Frontend

Blade templates + Livewire 3 for real-time UI. Alpine.js for client-side interactivity. The site is an installable web app (`/manifest.webmanifest`, `/sw.js`, `/offline`, `<x-pwa-head />`; the worker caches only the offline page and icons, never live data) and `/settings/health` has an opt-in browser notification toggle for warning/critical alerts (`public/js/remoment-pwa.js`, over the MQTT alert topics; opt-in background Web Push for a closed browser: `Alerts` → `Push::alert()` → queued `SendAlertPush` → `minishlink/web-push`, VAPID pair in settings with the private half encrypted, `POST|DELETE /api/push/subscribe`; unverified on real push services); see `docs/frontend/pwa.md`. Rendering stays in Livewire: `public/js/remoment-live.js` only listens to MQTT over WebSockets and triggers `$wire.$refresh()`, falling back to 1s polling when the broker is unreachable (see `docs/architecture/live-updates.md`). Tailwind CSS 3 is compiled by Vite (PostCSS); only Font Awesome comes from a CDN. The layout (`resources/views/layouts/app.blade.php`) provides a persistent sidebar navigation (top bar + drawer below `md`) and the sticky bottom player bar. **Read `DESIGN.md` before changing any view**: it defines the colour roles, radius scale, components and sticky stacking order every page must follow.

### Livewire Components

- `DeviceCard` (`app/Livewire/DeviceCard.php`) — device card with transport, volume, mute, and click-to-seek; on the single-device page (`standalone`) also lyrics and "Add to library" for the playing track (`Concerns/ManagesTrackExtras`); refreshes on MQTT push; shows color gradient from `ArtworkCache`; includes Multiroom button for capable devices
- `DeviceQueue` (`app/Livewire/DeviceQueue.php`) — "Up Next" list on the device page for `queue`-capable devices, loaded lazily via `wire:init`
- `SystemHealth` (`app/Livewire/SystemHealth.php`) — `/settings/health` tabs Services (`ExternalServicesHealth`), System & devices (this component: MQTT broker reachability, scheduler/queue-worker heartbeats, per-device listeners) and Queue (`QueueManager`: pending jobs per class (database and redis drivers) and the failed jobs with retry/forget/clear, a worker restart (`queue:restart`) and "Queue missing metadata" (`Enrichment::queueBacklog/queueEntities`), all in `Domain/Health/QueueInspector`)
- `DeviceSourceManager` (`app/Livewire/DeviceSourceManager.php`) — source list with hide/show toggle, drag-to-reorder, and activate button
- `MultiroomPresets` (`app/Livewire/MultiroomPresets.php`) — create/activate/delete named multiroom groupings
- `DeviceHistory` (`app/Livewire/DeviceHistory.php`) — last 10 unique tracks for a device
- `PlayHistory` (`app/Livewire/PlayHistory.php`) — paginated play history with device/source filters
- `ClientManager` (`app/Livewire/ClientManager.php`) — approve/reject pending registrations, edit client name/type/device assignment, regenerate tokens
- `SmartPlaylistEditor` (`app/Livewire/SmartPlaylistEditor.php`) — rule rows, live match count and track sample for a smart playlist (see `docs/architecture/smart-playlists.md`)
- `PlayerBar` (`app/Livewire/PlayerBar.php`) — sticky bottom player for the device pinned in this browser (cookie, `SelectedDevice`); library play buttons (`<x-play-button>`) play on it directly (see `docs/frontend/player-bar.md`)
- `SetupWizard` (`app/Livewire/SetupWizard.php`) — first-time setup checklist: add devices, client devices, library source (see `docs/architecture/setup-wizard.md`)

### Web Pages

- `/setup` — first-time setup wizard (see `docs/architecture/setup-wizard.md`); auto-redirected here for a logged-in admin while zero devices exist
- `/devices` — responsive device grid dashboard (playing devices get wide card, others get compact)
- `/devices/create` — add device with cascading brand→product→driver form (Alpine.js)
- `/devices/{id}` — device detail: nowplaying + up next queue + source manager + info panel
- `/devices/{id}/edit` — edit device name, IP, brand, product
- `/history` — full play history browser on the same domain code as `GET /api/library/history` (`Domain\Stats\PlayHistory`): search, device, source, dates, library vs unknown plays, `?albumId=`/`?artistId=` from the album and artist pages, cursor paging; the admin sets the retention (months, default forever) at the bottom; see `docs/api/history.md`
- `/tools` — the Tools hub and menu entry (`docs/frontend/tools-menu.md`), one card per tool the profile can run (Mixer; Export at `/tools/export`: the export list and how to start one)
- `/mixer` — stem mixer (Full profile, open; under Tools in the menu): two decks of kept stems (`?a=&b=` track ids), mixed in the browser; see `docs/frontend/stem-mixer.md`
- `/stats` — listening statistics (top artists, devices, hourly heatmap)
- `/multiroom` — multiroom session management and presets
- `/scenes`, `/scenes/create`, `/scenes/{id}/edit` — scenes: list with Run, and the editor built from the operations' schemas (`Livewire\SceneList`, `Livewire\SceneEditor`, and `Livewire\AddToScene` on the album, artist and playlist pages; see `docs/api/scenes.md`)
- `/receiver` — full-screen now-playing display; fetches `GET /api/devices`, lets user pick device (or auto-selects if only one); re-fetches `GET /api/devices/{id}` on MQTT push (polling every 3s without a broker) and supports play/pause/next/previous, seek and mute via the REST API. Accepts `?device={id}` query parameter to skip the picker.
- `/library` — listening hub: hero + Shuffle, section chips, shelves (recently played/added, most played artists, playlists, genres, favourites, discover), library at a glance (see `docs/frontend/library.md`); `/library/favorites`; `/library/tracks` — all tracks with Shuffle all
- `/artists` — artist library browser
- `/artists/{id}` — artist page (hero, source switch, popular, albums, similar); `/artists/{id}/settings` (admin) — see `docs/architecture/artist-settings.md`
- `/albums/{id}` — album detail with track listing
- `/settings` — overview of listeners, MQTT config
- `/settings/users` — Admin & Apps: the shared admin login (change password) and the apps signed in with it (revoke)
- `/settings/analysis` — audio analysis status: per-stage progress, what runs now, sidecar, problems with retry (see `docs/architecture/audio-analysis.md`)
- `/settings/developer` — developer tools (admin): quality test, simulate playback, MQTT console, device inspector, stream URL tester (see `docs/architecture/developer-tools.md`)
- `/settings/manager` — containers, restart, logs, host health and the guarded Upgrade / Roll back card (confirm, live result) and the Network shares (SMB) card (library read-only by default, exports, inbox, one share user whose generated password is shown once, preview = dry run) and the NAS mounts card (form, preview, apply with the preview's plan token; the NAS password is typed once and kept only in a root-only file on the host) through the ReMoment Manager host service (admin; `Livewire\ManagerPanel`, `Domain\Manager\ManagerClient`, `config/manager.php`; see `docs/architecture/manager.md`)
- `/settings/health` — external services (Spotify/Last.fm/Discogs/TheAudioDB credentials, MusicBrainz, Wikipedia, Cover Art Archive, LRCLIB, storage locations; `Domain/Health/ExternalServices`, one read-only probe each, results cached 15 min, "Check now" re-runs them), supervisor, scheduler, queue worker, pending jobs, MQTT broker and per-device listener health
- `/settings/devices` — discovered-devices list (scan, add, ignore) on top, drivers grouped by brand below, add by IP (see `docs/architecture/device-discovery.md`); `/devices/discover` redirects here
- `/settings/dlna` — discover DLNA servers, trigger library scans
- `/settings/storage` — Files & Import: file browser, upload and inbox import, storage locations (local/S3) with queued scan/reorganize and live status, and the Library tab (leading source, played tracks) (see `docs/architecture/file-storage.md`)
- `/settings/storage` also has an Exports tab (`Livewire\ExportList`) and the album, artist and playlist pages an admin-only Export button (`Livewire\ExportDialog`); both use `Domain\Export\ExportService`, the API's implementation (see `docs/architecture/export.md`); `php artisan export:prune` (hourly) is the retention The dialog also offers AAC (m4a) and Opus profiles (only those the server's ffmpeg can encode: `Domain\Export\ExportFormats`, `features.export_formats` in `/api/info`) and a `loudness` option (`off|album|track`: ReplayGain-style gain baked into transcoded files only, never past the true peak; copies stay bit-exact); see `docs/architecture/export.md`.
- `/settings/services` — Services & keys: every external service's credentials from one registry (`docs/architecture/credentials.md`)
- `/settings/spotify-connect` — map Spotify Connect speaker names to local devices
- `/settings/clients` — manage client device registrations; approve/reject pending, assign devices, view tokens; build/download the SD card artwork zips, one per screen size (see `docs/architecture/sd-card-export.md`)

### Controllers

- `DeviceController` (`app/Http/Controllers/DeviceController.php`) — full CRUD + standby + source activation (web)
- `Api/DeviceController` (`app/Http/Controllers/Api/DeviceController.php`) — REST API
- `HistoryController` — play history views
- `SettingsController` — settings, listeners, DLNA, Spotify Connect, client devices
- `Api/ClientController` (`app/Http/Controllers/Api/ClientController.php`) — client registration, status polling, device list, heartbeat
- `StatsController` — statistics views

---
