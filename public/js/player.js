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

    function status(text) { $('status').textContent = text; }

    async function api(path, options) {
        const response = await fetch('/api' + path, Object.assign({ headers: { 'Content-Type': 'application/json', Accept: 'application/json' } }, options));
        if (!response.ok) throw new Error(path + ' ' + response.status);
        return response.json();
    }

    async function register() {
        const name = $('name').value.trim() || 'Browser';
        store.set(NAME_KEY, name);
        const result = await api('/players/register', {
            method: 'POST',
            body: JSON.stringify({ token: store.get(TOKEN_KEY), name, kind: 'web' }),
        });
        token = result.player_token;
        deviceId = result.device_id;
        store.set(TOKEN_KEY, token);
        return result;
    }

    function show(track) {
        $('title').textContent = track ? track.name : 'Nothing playing';
        $('artist').textContent = track ? [track.artist, track.album].filter(Boolean).join(' · ') : '';
        const image = track && track.images && track.images[0];
        $('art').style.backgroundImage = image ? 'url("' + image + '")' : '';
        document.title = track ? track.name + ' · ReMoment Player' : 'ReMoment Player';

        if ('mediaSession' in navigator) {
            navigator.mediaSession.metadata = track ? new MediaMetadata({
                title: track.name, artist: track.artist || '', album: track.album || '',
                artwork: image ? [{ src: image }] : [],
            }) : null;
        }
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
            case 'resume': play(); break;
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

    function play() {
        audio.play().then(() => { pendingPlay = null; $('start').style.display = 'none'; }).catch(() => {
            pendingPlay = true;
            $('start').style.display = 'inline-block';
            status('Press "Start player" to allow audio.');
        });
    }

    function report(extra) {
        if (!token) return;
        const state = !current ? 'stopped' : (audio.paused || pendingPlay ? 'paused' : 'playing');
        const body = Object.assign({
            state,
            track_id: current ? current.id : null,
            position: Math.floor(audio.currentTime || 0),
            duration: Number.isFinite(audio.duration) ? Math.floor(audio.duration) : null,
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
        navigator.mediaSession.setActionHandler('seekto', e => { audio.currentTime = e.seekTime; report(); });
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

    async function init() {
        $('name').value = store.get(NAME_KEY) || (navigator.platform || 'Browser') + ' browser';
        const info = await register();
        setupMediaSession();
        connectMqtt(info);

        $('start').addEventListener('click', () => { if (current) play(); });
        $('name').addEventListener('change', () => register().catch(() => {}));
        audio.addEventListener('ended', () => { ended = true; report({ ended: true }); });
        audio.addEventListener('play', () => report());
        audio.addEventListener('pause', () => { if (!ended) report(); });
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
