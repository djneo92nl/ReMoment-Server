# Setup Wizard

Guides a fresh install (zero devices) through the three things a working system needs: audio devices, client devices, and a library source. It's a checklist page, not a rigid linear form — every action links to an existing page or setting; the wizard adds no new domain logic.

## Steps

1. **Add your devices** — links to `/devices/discover` (network scan, `DiscoverDevices` Livewire component) and `/devices/create` (manual add). Complete once `Device::count() > 0`. Not skippable — there's nothing to control with zero devices.
2. **Client devices** — explains the self-registration flow (see `docs/api/client-devices.md`) and links to `/settings/clients` for approval. Complete once `Client::count() > 0`, or explicitly skipped.
3. **Set up your library** — links to `/settings/dlna` and `/settings/spotify`, with a footnote link to `/settings/lastfm` for scrobbling (not a gated step). Complete once a DLNA server exists or Spotify is connected, or explicitly skipped.

## Wiring

- `App\Livewire\SetupWizard` (`resources/views/livewire/setup-wizard.blade.php`) computes step status on every render from the same counts/services `SettingsController::index()` already uses (`Device::count()`, `Client::count()`, `DlnaServer::count()`, `SpotifyTokenService::isConnected()`).
- Routed at `GET /setup` (`setup.index`), behind the app's single admin login (`auth` middleware), alongside `/settings/*` — two of the three steps link into auth-gated settings actions.
- Progress is stored in the existing generic `Setting` key/value table — no new migration:
  - `setup_step_clients_skipped` / `setup_step_library_skipped` — set when the admin clicks "Skip for now" on that step.
  - `setup_wizard_dismissed` — set when the admin clicks Finish/Skip on the whole wizard. Once set, the auto-redirect (below) never fires again, even if devices are later removed.
- `App\Http\Middleware\RedirectToSetupWizard` runs on every `web` request (registered in `bootstrap/app.php`). It redirects to `/setup` only when: the request targets `devices.index` or `dashboard` (the pages an admin actually lands on right after logging in — `login` itself redirects to `dashboard`, which redirects to `devices.index`), it's a plain `GET` page load (not a Livewire AJAX call — checked via the `X-Livewire` header), the visitor is authenticated (`auth()->check()`), the wizard hasn't been dismissed, and `Device::count() === 0`. It's deliberately scoped to just those two routes rather than "every page" — Settings and Profile pages must stay reachable even with zero devices, since the library step doesn't require a device at all.

## Auth model reminder

This app has exactly one shared admin login — see CLAUDE.md's "Auth Model" note. The wizard follows that model: it sits behind the one admin session like `/settings/*`, and its progress flags are global (not scoped to any user), matching the rest of the app.
