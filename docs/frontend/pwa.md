# Web app (PWA) and alert notifications (8.6)

The site can be installed ("Add to Home Screen" / "Install app") and can show browser notifications for alerts. Both are thin glue: Livewire stays the renderer, there is no JS framework.

## Installable app

| Piece | Where |
|---|---|
| Manifest | `GET /manifest.webmanifest` (`PwaController::manifest`): `name` = `APP_NAME`, `short_name` ReMoment, `display: standalone`, `start_url` `/devices`, theme and background colour stone-950 of DESIGN.md (`PwaController::THEME`), icons 192 and 512 |
| Icons | `public/icons/icon-{180,192,512}.png`: the music-note logo drawn by `LogoRenderer::render('music', $size)` (re-generate with that call if the logo changes) |
| Service worker | `GET /sw.js` (source `resources/pwa/sw.js`, `PwaController::serviceWorker`), served from the root with `Service-Worker-Allowed: /` and `Cache-Control: no-cache` so the browser always re-checks it. Bump `PwaController::SHELL_VERSION` when it changes how caches are laid out |
| Offline page | `GET /offline` (`resources/views/pwa/offline.blade.php`, standalone, no session) |
| Layout tags | `<x-pwa-head />` in `layouts/app.blade.php`: manifest link, `theme-color`, icon, `apple-touch-icon`, and `js/remoment-pwa.js` |

The three routes drop the session, cookie, CSRF and setup-redirect middleware like `/stream`, and do not depend on the profile or the database.

### What the service worker stores

Only the offline page and the app icons. **It never caches API, Livewire, artwork, stream or page responses**: the site is guest-open and live, and stale data is worse than none. A page load that fails (server off, no network) gets the offline page instead of the browser's error; every other request is not touched and goes to the network. A test (`tests/Feature/PwaTest.php`) pins this.

## Alert notifications (opt-in per browser)

A card on `/settings/health` (admin page; `settings/partials/alert-notifications.blade.php`, Alpine `alertNotifications`) turns them on and off:

- The browser's permission prompt appears **only on the click** of "Turn on". The choice (`remoment.alertNotifications`) is kept in this browser's localStorage; denying or "Turn off" keeps everything silent.
- `remoment-live.js` now also subscribes to `remoment/alerts/+` (MQTT over WebSocket) and offers `RemomentLive.onAlert(fn)`. `remoment-pwa.js` listens:
  - `remoment/alerts/{kind}` with `state: "raised"` notifies at once.
  - The retained `remoment/alerts/active` carries only counts, so when the number of warning plus critical alerts changes it re-reads `GET /api/alerts?state=active`, which also catches alerts raised before the tab opened.
- Only `warning` and `critical` notify. One notification per alert id and severity (a rise from warning to critical notifies again); seen ids are in localStorage (`remoment.alertsSeen`, newest 200). Notifications carry the tag `remoment-alert-{id}`, so two open tabs show one.
- Shown through the service worker (`registration.showNotification`, which Android needs), else `new Notification`. A click opens `/settings/health`. Critical ones stay until dismissed.
- Turning it on marks the alerts already active as seen without notifying: they are on the page you are looking at.

### Out of scope (a later item)

**No Web Push.** There is no push server, no VAPID keys, no stored subscriptions and no accounts. Notifications only appear while a tab or the installed app is open (the page holds the MQTT connection); a closed browser gets nothing. Background push is item 8.6 phase 3, planned for 1.1.

Browsers show notifications and register service workers only on **https or localhost**. On a plain `http://remoment.local` install the toggle says so and the app installs on some browsers only (Chrome needs https for the install prompt; Safari "Add to Home Screen" works but without a worker).

## Tests and manual checks

`tests/Feature/PwaTest.php`: manifest content type and fields, icons exist, service worker headers and what it caches, offline page, all three routes under every profile, layout link tags, the toggle on `/settings/health`, and that the permission request exists only in the click handler. There is no JS test setup in the project, so the browser side is checked by hand:

1. Open `/settings/health` on https or localhost, press "Turn on", allow, press "Send a test": a notification appears; click it: `/settings/health` opens.
2. Raise an alert (for example stop the queue worker until `queue.stalled` fires, or publish a non-retained `{"id":9001,"state":"raised","severity":"critical","title":"Test","message":"Hi"}` to `remoment/alerts/test` from the MQTT console on `/settings/developer`): one notification, and not a second one after a reload.
3. DevTools › Application: Manifest has no errors; Service Workers shows `/sw.js`; Cache Storage holds only `remoment-shell-v1` with `/offline` and two icons. Tick "Offline" and reload a page: the offline page shows.
