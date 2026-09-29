/*
 * ReMoment live updates — thin glue between MQTT-over-WebSockets and the UI.
 *
 * Subscribes to remoment/player/+/+ on the Mosquitto WebSocket listener and
 * re-emits each message as { deviceId, kind, data, retained }. Nothing is
 * rendered here: Livewire components use it to trigger $wire.$refresh(),
 * and /receiver uses it to re-fetch the device instead of polling.
 *
 * Requires mqtt.js (window.mqtt) to be loaded first. If the broker can't be
 * reached, RemomentLive.connected stays false and callers keep polling.
 */
(function () {
    if (window.RemomentLive) return;

    const handlers = new Set();

    const live = {
        connected: false,
        on(fn) {
            handlers.add(fn);
            return () => handlers.delete(fn);
        },
    };
    window.RemomentLive = live;

    function brokerUrl() {
        const meta = document.querySelector('meta[name="remoment-mqtt-ws"]');
        if (meta && meta.content) return meta.content;
        const scheme = location.protocol === 'https:' ? 'wss' : 'ws';
        return `${scheme}://${location.hostname}:9001`;
    }

    function emit(detail) {
        handlers.forEach(fn => {
            try { fn(detail); } catch (e) { console.error(e); }
        });
    }

    function start() {
        if (!window.mqtt) return;

        const client = window.mqtt.connect(brokerUrl(), {
            clientId: 'remoment-web-' + Math.random().toString(16).slice(2, 10),
            reconnectPeriod: 5000,
            connectTimeout: 5000,
        });

        client.on('connect', () => {
            live.connected = true;
            client.subscribe('remoment/player/+/+');
        });
        client.on('close', () => { live.connected = false; });
        client.on('error', () => { /* reconnect handles it; polling covers the gap */ });

        client.on('message', (topic, payload, packet) => {
            const match = topic.match(/^remoment\/player\/(\d+)\/(\w+)$/);
            if (!match) return;

            const text = payload.toString();
            let data = text;
            try { data = JSON.parse(text); } catch (e) { /* plain string payload */ }

            emit({ deviceId: parseInt(match[1], 10), kind: match[2], data, retained: !!packet.retain });
        });
    }

    start();

    // Livewire glue. Alpine is started by Livewire from the Vite bundle, which
    // loads after this script, so these registrations land before init.
    document.addEventListener('alpine:init', () => {
        const Alpine = window.Alpine;

        // Put on a Livewire component's root. Refreshes on push messages for
        // this device; falls back to polling every `fallbackMs` while the
        // broker is unreachable, and does a slow safety refresh when it is.
        Alpine.data('liveDevice', (deviceId, fallbackMs = 1000, safetyMs = 30000) => ({
            init() {
                let lastRefresh = Date.now();
                let pending = null;

                const refresh = () => {
                    lastRefresh = Date.now();
                    this.$wire.$refresh();
                };

                this._off = live.on(msg => {
                    // Retained messages describe state the page was already rendered with;
                    // progress ticks are handled client-side by progressTicker.
                    if (msg.deviceId !== deviceId || msg.retained || msg.kind === 'progress') return;
                    clearTimeout(pending);
                    pending = setTimeout(refresh, 150);
                });

                this._timer = setInterval(() => {
                    const interval = live.connected ? safetyMs : fallbackMs;
                    if (Date.now() - lastRefresh >= interval) refresh();
                }, Math.min(fallbackMs, 1000));
            },
            destroy() {
                this._off && this._off();
                clearInterval(this._timer);
            },
        }));

        // Advances a progress bar locally between server renders. Resyncs by
        // asking Livewire to refresh when the pushed progress (a percentage)
        // drifts from the local estimate, e.g. after a seek.
        Alpine.data('progressTicker', (deviceId, position, duration, playing) => ({
            pos: position,
            init() {
                this._timer = setInterval(() => {
                    if (playing && this.pos < duration) this.pos++;
                }, 1000);
                this._off = live.on(msg => {
                    if (msg.deviceId !== deviceId || msg.kind !== 'progress' || !duration) return;
                    const pushed = parseInt(msg.data, 10);
                    if (!isNaN(pushed) && Math.abs(pushed - this.pct) > 3) this.$wire.$refresh();
                });
            },
            destroy() {
                clearInterval(this._timer);
                this._off && this._off();
            },
            get pct() {
                return duration > 0 ? Math.min(100, (this.pos / duration) * 100) : 0;
            },
            get elapsed() {
                const s = Math.max(0, Math.floor(this.pos));
                return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
            },
            seekTo(event) {
                const rect = event.currentTarget.getBoundingClientRect();
                const fraction = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
                this.pos = Math.round(fraction * duration);
                this.$wire.seek(this.pos);
            },
        }));
    });
})();
