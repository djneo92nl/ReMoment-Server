/*
 * ReMoment web player: a browser tab that plays the server's own files as a ReMoment device.
 * Protocol: docs/api/players.md. Commands arrive over MQTT-over-WebSocket (remoment/playercmd/{id}),
 * or by polling GET /api/players/{token}/commands when the broker can't be reached. State goes back
 * with PUT /api/players/{token}/state every few seconds (also the heartbeat).
 */
(function () {
    const TOKEN_KEY = 'remoment_player_token';
    const NAME_KEY = 'remoment_player_name';
    const $ = id => document.getElementById(id);
    const audio = new Audio();
    audio.preload = 'auto';

    let token = null;
    let deviceId = null;
    let lastCommand = 0;
    let current = null;       // the track object of the last play command
    let pendingPlay = null;   // a play blocked by the browser's autoplay rule
    let ended = false;
    let mqttClient = null;

    const store = {
        get: k => { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } },
    };

    /** "Safari on Mac": the platform alone is the same in every browser. */
    function defaultName() {
        const ua = navigator.userAgent;
        const browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
        const os = /iPhone|iPad/.test(ua) ? 'iOS' : /Android/.test(ua) ? 'Android' : /Mac/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : /Linux/.test(ua) ? 'Linux' : '';
        return os ? browser + ' on ' + os : browser;
    }

    function status(text) { $('status').textContent = text; }

    async function api(path, options) {
        const response = await fetch('/api' + path, Object.assign({ headers: { 'Content-Type': 'application/json', Accept: 'application/json' } }, options));
        if (!response.ok) throw new Error(path + ' ' + response.status);
        return response.json();
    }

    async function register(adopt) {
        const name = $('name').value.trim() || 'Browser';
        store.set(NAME_KEY, name);
        const result = await api('/players/register', {
            method: 'POST',
            body: JSON.stringify({ token: store.get(TOKEN_KEY), name, kind: 'web', adopt_device_id: adopt || null }),
        });
        if (adopt) $('name').value = result.name;
        store.set(NAME_KEY, $('name').value);
        token = result.player_token;
        deviceId = result.device_id;
        store.set(TOKEN_KEY, token);
        return result;
    }

    function show(track) {
        $('title').textContent = track ? track.name : 'Nothing playing';
        $('artist').textContent = track ? [track.artist, track.album].filter(Boolean).join(' · ') : '';
        const image = track && track.images && track.images[0];
        setArt(image);
        // The cover may still be processing when the track starts: ask the device for it a few times.
        if (track && !image && !track.live) fetchArt(track.id, 5);
        document.title = track ? track.name + ' · ReMoment Player' : 'ReMoment Player';

        if ('mediaSession' in navigator) {
            navigator.mediaSession.metadata = track ? new MediaMetadata({
                title: track.name, artist: track.artist || '', album: track.album || '',
                artwork: image ? [{ src: image }] : [],
            }) : null;
        }
    }

    function setArt(image) {
        $('art').style.backgroundImage = image ? 'url("' + image + '")' : '';
        if (current && image) current.images = [image];
        if ('mediaSession' in navigator && navigator.mediaSession.metadata && image) {
            const m = navigator.mediaSession.metadata;
            navigator.mediaSession.metadata = new MediaMetadata({ title: m.title, artist: m.artist, album: m.album, artwork: [{ src: image }] });
        }
    }

    function fetchArt(trackId, tries) {
        setTimeout(() => {
            if (!current || current.id !== trackId) return;
            api('/devices/' + deviceId).then(r => {
                const artwork = r.data && r.data.now_playing && r.data.now_playing.artwork;
                const path = artwork && (artwork.proxy_512 || artwork.proxy_320);
                const i = path ? path.indexOf('/storage/') : -1;
                if (i >= 0) setArt(path.slice(i));
                else if (tries > 1) fetchArt(trackId, tries - 1);
            }).catch(() => {});
        }, 3000);
    }

    function handle(command) {
        if (command.id <= lastCommand) return;
        lastCommand = command.id;

        switch (command.op) {
            case 'play':
                current = command.track;
                ended = false;
                audio.src = current.url;
                show(current);
                play();
                break;
            case 'resume':
                // A paused live stream would play its stale buffer: reconnect to the live edge.
                if (current && current.live) audio.src = current.url;
                play();
                break;
            case 'pause': audio.pause(); break;
            case 'stop':
                audio.pause();
                audio.removeAttribute('src');
                current = null;
                show(null);
                break;
            case 'seek': audio.currentTime = command.position; break;
            case 'volume': audio.volume = command.volume / 100; break;
            case 'mute': audio.muted = !!command.muted; break;
        }
        report();
    }

    // Browsers only allow audio in a tab after a click or key press in it. So the page asks for one up front,
    // on the same <audio> element (Safari unlocks per element), and every later play() just works.
    const SILENCE = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';
    let unlocked = false;
    let ignoreEventsUntil = 0; // the silent unlock sound must not look like a track that ended

    function showLocked(locked) {
        $('start').style.display = locked ? 'inline-block' : 'none';
        if (locked) status('Click anywhere on this page once to allow audio.');
    }

    function unlock() {
        if (unlocked) return Promise.resolve();
        ignoreEventsUntil = Date.now() + 1500;
        const keep = audio.getAttribute('src');
        const wasPlaying = !audio.paused;
        if (!wasPlaying) audio.src = SILENCE;
        return audio.play().then(() => {
            unlocked = true;
            document.removeEventListener('pointerdown', unlock);
            document.removeEventListener('keydown', unlock);
            if (!wasPlaying) {
                audio.pause();
                if (keep) audio.src = keep; else audio.removeAttribute('src');
            }
            showLocked(false);
            if (pendingPlay && current) play();
        }).catch(() => { if (!wasPlaying && keep) audio.src = keep; showLocked(true); });
    }

    function play() {
        audio.play().then(() => { pendingPlay = null; }).catch(() => {
            pendingPlay = true;
            showLocked(true);
        });
    }

    function report(extra) {
        if (!token) return;
        const live = current && current.live;
        const state = !current ? 'stopped' : (audio.paused || pendingPlay ? 'paused' : 'playing');
        const body = Object.assign({
            state,
            track_id: current && !live ? current.id : null,
            position: live ? 0 : Math.floor(audio.currentTime || 0),
            duration: !live && Number.isFinite(audio.duration) ? Math.floor(audio.duration) : null,
            volume: Math.round(audio.volume * 100),
            muted: audio.muted,
        }, extra);
        api('/players/' + token + '/state', { method: 'PUT', body: JSON.stringify(body) })
            .then(() => status('Online as ' + $('name').value))
            .catch(e => status('Server not reachable (' + e.message + ')'));
        const bar = $('bar').firstElementChild;
        bar.style.width = (audio.duration ? (audio.currentTime / audio.duration) * 100 : 0) + '%';
    }

    function setupMediaSession() {
        if (!('mediaSession' in navigator)) return;
        const act = (op) => () => api('/devices/' + deviceId + '/' + op, { method: 'POST' });
        navigator.mediaSession.setActionHandler('play', () => play());
        navigator.mediaSession.setActionHandler('pause', () => { audio.pause(); report(); });
        navigator.mediaSession.setActionHandler('nexttrack', act('next'));
        navigator.mediaSession.setActionHandler('previoustrack', act('previous'));
        navigator.mediaSession.setActionHandler('seekto', e => { if (!(current && current.live)) { audio.currentTime = e.seekTime; report(); } });
    }

    function connectMqtt(info) {
        if (!window.mqtt) return;
        const host = info.mqtt && info.mqtt.host ? info.mqtt.host : location.hostname;
        const meta = document.querySelector('meta[name="remoment-mqtt-ws"]');
        const url = meta && meta.content ? meta.content : (location.protocol === 'https:' ? 'wss' : 'ws') + '://' + host + ':' + info.mqtt.ws_port;
        mqttClient = window.mqtt.connect(url, { reconnectPeriod: 5000, connectTimeout: 5000 });
        mqttClient.on('connect', () => mqttClient.subscribe(info.command_topic, { qos: 1 }));
        mqttClient.on('message', (topic, payload) => {
            try { handle(JSON.parse(payload.toString())); } catch (e) { console.error(e); }
        });
    }

    async function poll() {
        if (mqttClient && mqttClient.connected) return;
        try {
            const result = await api('/players/' + token + '/commands?since=' + lastCommand);
            result.commands.forEach(handle);
        } catch (e) { /* the state report shows the problem */ }
    }

    /** A browser without a token of its own may take over an existing player instead of adding one. */
    function chooseExisting() {
        if (store.get(TOKEN_KEY)) return Promise.resolve(null);

        return api('/players').then(list => {
            const players = list.data.filter(p => p.kind === 'web');
            if (!players.length) return null;

            const select = $('existing');
            select.innerHTML = '<option value="">Add as a new player</option>';
            players.forEach(p => {
                const option = document.createElement('option');
                option.value = p.id;
                option.textContent = p.name + (p.state === 'unreachable' ? ' (offline)' : '');
                select.appendChild(option);
            });
            // Offline players first in line: they are the ones this browser most likely replaces.
            const offline = players.find(p => p.state === 'unreachable');
            if (offline) select.value = offline.id;

            $('choose').style.display = 'block';
            return new Promise(resolve => $('use').addEventListener('click', () => {
                $('choose').style.display = 'none';
                resolve(select.value ? parseInt(select.value, 10) : null);
            }, { once: true }));
        }).catch(() => null);
    }

    async function init() {
        $('name').value = store.get(NAME_KEY) || defaultName();
        const info = await register(await chooseExisting());
        lastCommand = info.last_command_id || 0;
        setupMediaSession();
        connectMqtt(info);

        document.addEventListener('pointerdown', unlock);
        document.addEventListener('keydown', unlock);
        unlock();
        $('name').addEventListener('change', () => register().catch(() => {}));
        audio.addEventListener('ended', () => {
            if (!current || Date.now() < ignoreEventsUntil || audio.src.startsWith('data:')) return;
            ended = true;
            report({ ended: true });
        });
        audio.addEventListener('error', () => {
            if (!current || Date.now() < ignoreEventsUntil || audio.src.startsWith('data:')) return;
            status('Could not play ' + current.name + (location.protocol === 'https:' && /^http:/.test(current.url) ? ' (an http stream is blocked on an https page)' : ''));
            if (current.live) { ended = true; report({ ended: true }); }
        });
        audio.addEventListener('play', () => { if (Date.now() >= ignoreEventsUntil) report(); });
        audio.addEventListener('pause', () => { if (!ended && Date.now() >= ignoreEventsUntil) report(); });
        audio.addEventListener('seeked', () => report());
        audio.addEventListener('volumechange', () => report());
        // A second tab with the same token takes over: nothing to do here beyond reporting; the newest
        // tab's commands are the ones the server gets state from.
        setInterval(() => { report(); }, 5000);
        setInterval(poll, 2000);
        report();
        status('Online as ' + $('name').value);
    }

    window.addEventListener('DOMContentLoaded', () => init().catch(e => status('Could not register: ' + e.message)));
})();
