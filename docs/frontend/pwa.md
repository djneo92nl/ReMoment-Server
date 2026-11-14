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

## Background Web Push (phase 3, opt-in per browser)

Wakes a **closed** browser. A second button in the same card on `/settings/health` ("Also when the browser is closed"). No accounts: a subscription is a row for one browser, nothing says whose it is.

| Piece | Where |
|---|---|
| Subscriptions | table `push_subscriptions` (`endpoint` unique, `p256dh`, `auth`, `user_agent`, `last_success_at`, timestamps; no user id), model `App\Models\PushSubscription` |
| VAPID key pair | `App\Domain\Push\Vapid`: made once (by `php artisan push:vapid` or on first use of the settings page), public key in the setting `push_vapid_public`, private key **encrypted** (`Crypt`) in `push_vapid_private`. The private key is in `ServiceCredentials::secretSettingKeys()`, so backups and the diagnostics bundle leave it out (no `--with-secrets`: restore, then `push:vapid --rotate` or let browsers subscribe again). It is never in git, `.env` or any response; the public key is rendered into the card as `data-vapid-key` |
| Sender | `App\Domain\Push\PushSender` (interface), `WebPushSender` = `minishlink/web-push` ^11 (RFC 8030/8291/8292: aes128gcm encryption, VAPID JWT). It was checked to install on PHP 8.4 and needs only `curl`, `mbstring` and `openssl`, which the app image has; `gmp`/`bcmath` only speed it up. Tests bind `Tests\Support\FakePushSender` |
| Delivery | `Alerts` calls `Push::alert()` when an alert is **new** or its **severity changed**; it queues `Jobs\SendAlertPush(alertId, severity)` only for `warning`/`critical` and only if a subscription exists. The job runs on the default queue (every profile has it; alerts are on in all three) and sends once per alert id and severity (`Cache::add` key `push:alert:{id}:{severity}`, one day), so a retry or a message change does not notify twice and a rise to `critical` does. A 404/410 from the push service deletes that subscription; other failures are logged and the row stays. Every step is wrapped: **a push failure never stops an alert from being raised** (`Push::alert` and `Alerts::raise` both catch) |
| Payload | `{ id, severity, title, body, tag: "remoment-alert-{id}", url: "/settings/health" }`; the tag equals the in-page notification's, so an open tab and a push show one notification |
| Endpoints | `POST /api/push/subscribe` `{ endpoint, keys: { p256dh, auth } }` (201 new, 200 refresh) and `DELETE /api/push/subscribe` `{ endpoint }`; guest-open like the rest of the API, `throttle:20,1`; `openapi.yaml` operationIds `subscribePush`, `unsubscribePush` |
| Worker | `resources/pwa/sw.js`: a `push` handler (shows the notification, `requireInteraction` for critical, only same-site `url`s) and the existing `notificationclick`. It still caches nothing but the offline shell |
| Commands | `php artisan push:vapid [--rotate --force]` creates or shows the **public** key (rotating deletes every subscription); `php artisan push:test [--message=]` pushes a test to every stored browser without raising an alert |
| Config | `config/push.php`: `PUSH_VAPID_SUBJECT` (the VAPID `sub`, default `mailto:remoment@localhost`; no personal address is ever filled in), `PUSH_ALLOWED_HOSTS`, `PUSH_MAX_SUBSCRIPTIONS` (100) |

**Safety choices.** The endpoint is a URL a guest posts and the server then calls, so it must be `https`, with no IP address, user info or odd port, on a host of a known push service (`googleapis.com`, `push.services.mozilla.com`, `push.apple.com`, `notify.windows.com`, subdomains included; extend with `PUSH_ALLOWED_HOSTS`); anything else is `422 invalid`. At most 100 subscriptions are kept (`422 too_many`). Opting in is explicit: the permission prompt appears only on a click.

**What the user sees.** Needs **https** (or localhost). On an iPhone or iPad Web Push works only for the app installed to the Home Screen (Share › Add to Home Screen) and opened from there once; the card says so. Turning it off unsubscribes the browser and deletes its row. The choice is also kept in localStorage (`remoment.pushNotifications`); the card re-checks the browser's real subscription on load.

**Not built:** re-subscribing after the browser rotates a subscription (`pushsubscriptionchange`: the next click on the toggle makes a new row; the old one is removed when the push service answers 410), per-alert or per-device filters, a push for `info` alerts, and sending from a hosted relay. A restored backup starts without keys (see above).

Browsers show notifications and register service workers only on **https or localhost**. On a plain `http://remoment.local` install the toggle says so and the app installs on some browsers only (Chrome needs https for the install prompt; Safari "Add to Home Screen" works but without a worker).

## Tests and manual checks

`tests/Feature/PwaTest.php`: manifest content type and fields, icons exist, service worker headers and what it caches, offline page, all three routes under every profile, layout link tags, the toggle on `/settings/health`, and that the permission request exists only in the click handler. There is no JS test setup in the project, so the browser side is checked by hand:

1. Open `/settings/health` on https or localhost, press "Turn on", allow, press "Send a test": a notification appears; click it: `/settings/health` opens.
2. Raise an alert (for example stop the queue worker until `queue.stalled` fires, or publish a non-retained `{"id":9001,"state":"raised","severity":"critical","title":"Test","message":"Hi"}` to `remoment/alerts/test` from the MQTT console on `/settings/developer`): one notification, and not a second one after a reload.
3. DevTools › Application: Manifest has no errors; Service Workers shows `/sw.js`; Cache Storage holds only `remoment-shell-v1` with `/offline` and two icons. Tick "Offline" and reload a page: the offline page shows.
4. Background push (https or localhost): press "Turn on background", allow, then close every tab (on iOS: the installed app, from the Home Screen). Run `php artisan push:test` on the server: a notification appears with the browser closed; clicking it opens `/settings/health`. Raise a warning with the alert test above and check one notification only. Press "Turn off background": the row disappears from `push_subscriptions`.

**Unverified on real browsers and push services:** all of phase 3. Tests use a fake sender; no real FCM, Mozilla or Apple call was made, so the encrypted request, the VAPID subject (some services may reject a `localhost` mailto: set `PUSH_VAPID_SUBJECT` to a real contact if Apple or Mozilla refuse), the iOS installed-app path and the host allow list are checked by reading the specs only.
