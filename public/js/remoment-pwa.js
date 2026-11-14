/*
 * ReMoment web app glue: registers the service worker (sw.js, offline page only) and shows
 * opt-in browser notifications for alerts while a tab or the installed app is open.
 *
 * Alerts come from the MQTT-over-WebSocket connection of remoment-live.js (remoment/alerts/{kind},
 * and the retained remoment/alerts/active that only carries counts, so a change there re-reads
 * GET /api/alerts?state=active). Only warning and critical alerts notify, once per alert id and
 * severity (kept in localStorage). No push service, no accounts: a closed browser gets nothing.
 * Livewire stays the renderer; the only UI is the Alpine toggle `alertNotifications` on /settings/health.
 */
(function () {
    if (window.RemomentAlerts) return;

    const KEY_ENABLED = 'remoment.alertNotifications';
    const KEY_SEEN = 'remoment.alertsSeen';
    const LEVELS = ['warning', 'critical'];

    const store = {
        get(key) { try { return localStorage.getItem(key); } catch (e) { return null; } },
        set(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* private mode: no dedupe, no opt-in kept */ } },
        remove(key) { try { localStorage.removeItem(key); } catch (e) { } },
    };

    const supported = 'Notification' in window;
    const isEnabled = () => supported && Notification.permission === 'granted' && store.get(KEY_ENABLED) === '1';

    function seen() {
        try { return JSON.parse(store.get(KEY_SEEN) || '{}') || {}; } catch (e) { return {}; }
    }

    function remember(map) {
        const ids = Object.keys(map);
        // keep the newest 200 ids; ids only grow
        ids.sort((a, b) => Number(a) - Number(b)).slice(0, Math.max(0, ids.length - 200)).forEach(id => delete map[id]);
        store.set(KEY_SEEN, JSON.stringify(map));
    }

    async function show(alert) {
        const title = alert.title || 'ReMoment alert';
        const options = {
            body: alert.message || '',
            tag: 'remoment-alert-' + alert.id, // a second tab showing the same alert replaces, not doubles it
            icon: '/icons/icon-192.png',
            data: { url: '/settings/health' },
            requireInteraction: alert.severity === 'critical',
        };
        try {
            const reg = 'serviceWorker' in navigator ? await navigator.serviceWorker.getRegistration() : null;
            if (reg) return await reg.showNotification(title, options);
        } catch (e) { /* fall through to the page API */ }
        const n = new Notification(title, options);
        n.onclick = () => { window.focus(); location.href = options.data.url; n.close(); };
    }

    // Notify for each warning/critical alert not yet seen at that severity; always records them as seen.
    function consider(alerts, { silent = false } = {}) {
        const map = seen();
        let changed = false;
        alerts.forEach(a => {
            if (!a || !LEVELS.includes(a.severity) || a.state === 'resolved' || a.resolved_at) return;
            if (map[a.id] === a.severity) return;
            map[a.id] = a.severity;
            changed = true;
            if (!silent && isEnabled()) show(a);
        });
        if (changed) remember(map);
    }

    async function readActive(opts) {
        try {
            const res = await fetch('/api/alerts?state=active&limit=50', { headers: { Accept: 'application/json' } });
            if (res.ok) consider((await res.json()).data || [], opts);
        } catch (e) { /* offline: the next message tries again */ }
    }

    window.RemomentAlerts = { supported, isEnabled, consider, readActive };

    // Service worker: offline page only (see resources/pwa/sw.js).
    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => { }));
    }

    function listen() {
        if (!window.RemomentLive || !window.RemomentLive.onAlert) return;
        let lastTotal = null;
        window.RemomentLive.onAlert(({ topic, data }) => {
            if (!isEnabled() || !data) return;
            if (topic === 'active') {
                // Counts only: re-read the list when the number of warnings/criticals changed (or on first sight).
                const c = data.counts || {};
                const total = (c.warning || 0) + (c.critical || 0);
                if (total !== lastTotal) { lastTotal = total; if (total > 0) readActive(); }
            } else if (data.state === 'raised') {
                consider([data]);
            }
        });
    }
    listen();

    document.addEventListener('alpine:init', () => {
        // The opt-in toggle: permission is asked only on this click; the choice lives in this browser.
        window.Alpine.data('alertNotifications', () => ({
            supported,
            permission: supported ? Notification.permission : 'denied',
            enabled: isEnabled(),
            async toggle() {
                if (this.enabled) {
                    store.remove(KEY_ENABLED);
                    this.enabled = false;
                    return;
                }
                if (!supported) return;
                this.permission = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission;
                if (this.permission !== 'granted') return;
                store.set(KEY_ENABLED, '1');
                this.enabled = true;
                // What is already active is on this page; only later alerts notify.
                readActive({ silent: true });
            },
            async test() {
                if (this.enabled) show({ id: 'test', title: 'ReMoment', message: 'Notifications work in this browser.', severity: 'warning' });
            },
        }));
    });
})();
