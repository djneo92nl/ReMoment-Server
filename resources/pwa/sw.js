/*
 * ReMoment service worker. Deliberately tiny: the site is live and open to guests, so stale
 * data is worse than none. It caches ONLY the offline page and the app icons. API, Livewire,
 * artwork, streams and every page are never cached; a page request that fails (server or
 * network down) gets the offline page instead of the browser's error.
 * It also shows alert notifications (a page asks it to) and opens /settings/health on click.
 */
const SHELL = 'remoment-shell-__SHELL_VERSION__';
const PRECACHE = ['/offline', '/icons/icon-192.png', '/icons/icon-512.png'];

self.addEventListener('install', event => {
    event.waitUntil(caches.open(SHELL).then(cache => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(k => k.startsWith('remoment-shell-') && k !== SHELL).map(k => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Icons: cache first (they only change with a release, which bumps the shell version).
    if (url.pathname.startsWith('/icons/')) {
        event.respondWith(caches.match(request).then(hit => hit || fetch(request)));
        return;
    }

    // Page loads: always the network; only when it fails, the offline page. Never stored.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/offline')));
    }
    // Everything else (API, Livewire, artwork, scripts): not touched, goes straight to the network.
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/settings/health';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windows => {
            for (const w of windows) {
                if ('focus' in w) {
                    if ('navigate' in w) w.navigate(target);
                    return w.focus();
                }
            }
            return self.clients.openWindow(target);
        })
    );
});
