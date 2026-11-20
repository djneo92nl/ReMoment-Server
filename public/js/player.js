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
        const ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timer = ctl ? setTimeout(() => ctl.abort(), 15000) : null;
        let response;
        try {
            response = await fetch('/api' + path, Object.assign({ headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, signal: ctl ? ctl.signal : undefined }, options));
        } catch (e) {
            throw new Error(path + (e && e.name === 'AbortError' ? ' timed out' : ' failed: ' + (e && e.message)));
        } finally { if (timer) clearTimeout(timer); }
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
        if (track) fetchArt(track.id, 5); else setAccent(null);
        refreshQueue();
        if (track) $('quick').style.display = 'none'; else loadQuick();
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
        $('bg').style.backgroundImage = image ? 'url("' + image + '")' : '';
        $('bg').style.opacity = image ? 1 : 0;
        if (current && image) current.images = [image];
        if ('mediaSession' in navigator && navigator.mediaSession.metadata && image) {
            const m = navigator.mediaSession.metadata;
            navigator.mediaSession.metadata = new MediaMetadata({ title: m.title, artist: m.artist, album: m.album, artwork: [{ src: image }] });
        }
    }

    function setAccent(color) {
        document.documentElement.style.setProperty('--accent', color || '#ffffff');
    }

    function fetchArt(trackId, tries) {
        setTimeout(() => {
            if (!current || current.id !== trackId) return;
            api('/devices/' + deviceId).then(r => {
                const artwork = r.data && r.data.now_playing && r.data.now_playing.artwork;
                const path = artwork && (artwork.proxy_512 || artwork.proxy_320);
                const i = path ? path.indexOf('/storage/') : -1;
                if (i >= 0) {
                    setArt(path.slice(i));
                    const pick = (artwork.safe_colors || artwork.colors || [])[1];
                    setAccent(pick || null);
                } else if (tries > 1) fetchArt(trackId, tries - 1);
            }).catch(() => {});
        }, 1500);
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
        if (locked) status('Tap "Allow audio" (or anywhere on the page) once to start playing.');
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
        updateUi();
    }

    const PLAY_ICON = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
    const PAUSE_ICON = '<svg viewBox="0 0 24 24"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>';

    function fmt(seconds) {
        seconds = Math.max(0, Math.floor(seconds || 0));
        return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
    }

    /** Progress, times, play/pause icon and volume controls follow the audio element. */
    function updateUi() {
        const live = current && current.live;
        const known = Number.isFinite(audio.duration) && audio.duration > 0 && !live;
        $('bar').firstElementChild.style.width = (known ? (audio.currentTime / audio.duration) * 100 : 0) + '%';
        $('elapsed').textContent = live ? 'Live' : fmt(audio.currentTime);
        $('total').textContent = known ? fmt(audio.duration) : (live ? '' : '0:00');
        const playing = current && !audio.paused && !pendingPlay;
        $('playpause').innerHTML = playing ? PAUSE_ICON : PLAY_ICON;
        $('playpause').title = $('playpause').ariaLabel = playing ? 'Pause' : 'Play';
        const v = audio.muted ? 0 : Math.round(audio.volume * 100);
        $('vol').value = v;
        $('vol').style.setProperty('--v', v + '%');
    }

    /** Standby: offer the latest album, radio station and track to start with one click. */
    function storagePath(artwork) {
        const path = artwork && (artwork.proxy_120 || artwork.proxy_320 || artwork.proxy_512);
        const i = path ? path.indexOf('/storage/') : -1;
        return i >= 0 ? path.slice(i) : null;
    }

    function loadQuick() {
        if (!deviceId) return;
        Promise.all([
            api('/library/recent-items?limit=30').then(r => r.data || []).catch(() => []),
            api('/library/history?match=library&limit=20').then(r => r.data || []).catch(() => []),
        ]).then(([items, history]) => {
            if (current) return;
            const tiles = [];
            const album = items.find(i => i.type === 'album' && i.album);
            const radio = items.find(i => i.type === 'radio' && i.station);
            const track = history.find(h => h.type === 'track' && h.track_id);
            const post = (path, body) => () => api('/devices/' + deviceId + path, { method: 'POST', body: body ? JSON.stringify(body) : undefined })
                .catch(e => status('Could not start that (' + e.message + ')'));
            if (album) tiles.push({ label: 'Album', title: album.album.name, sub: album.album.artist_name, art: storagePath(album.album.artwork), run: post('/library/play-album', { album_id: album.album.id }) });
            if (track) tiles.push({ label: 'Track', title: track.name, sub: track.artist_name, art: storagePath(track.artwork), run: post('/library/play', { track_id: track.track_id }) });
            if (radio) tiles.push({ label: 'Radio', title: radio.station.name, sub: radio.station.genre, art: storagePath(radio.station.artwork), run: post('/radio/' + radio.station.id) });

            const box = $('quick');
            const grid = box.querySelector('.tiles');
            grid.innerHTML = '';
            tiles.forEach(t => {
                const b = document.createElement('button');
                b.type = 'button'; b.className = 'tile';
                const img = document.createElement('span'); img.className = 'img';
                if (t.art) img.style.backgroundImage = 'url("' + t.art + '")';
                const tag = document.createElement('b'); tag.textContent = t.label; img.appendChild(tag);
                const title = document.createElement('span'); title.className = 't'; title.textContent = t.title || '';
                const sub = document.createElement('span'); sub.className = 's'; sub.textContent = t.sub || '\u00a0';
                b.append(img, title, sub);
                b.addEventListener('click', () => { unlock(); t.run(); });
                grid.appendChild(b);
            });
            grid.style.gridTemplateColumns = 'repeat(' + Math.max(1, tiles.length) + ', 1fr)';
            box.style.display = tiles.length ? 'block' : 'none';
        });
    }

    let modes = { shuffle: false, repeat: 'off' };

    function refreshModes() {
        if (!deviceId) return;
        api('/devices/' + deviceId).then(r => {
            const caps = r.data.capabilities || [];
            const m = (r.data.now_playing && r.data.now_playing.modes) || {};
            modes = { shuffle: !!m.shuffle, repeat: m.repeat || 'off' };
            $('shuffle').hidden = !caps.includes('shuffle');
            $('repeat').hidden = !caps.includes('repeat');
            $('shuffle').classList.toggle('on', modes.shuffle);
            $('repeat').classList.toggle('on', modes.repeat !== 'off');
            $('repeat').title = 'Repeat: ' + modes.repeat;
        }).catch(() => {});
    }

    function refreshQueue() {
        if (!deviceId) return;
        // The server advances its queue a moment after the player reports a track change.
        setTimeout(() => {
            api('/devices/' + deviceId + '/queue?limit=5').then(r => {
                const list = $('queue');
                list.innerHTML = '';
                (r.up_next || []).forEach(item => {
                    const li = document.createElement('li');
                    const name = document.createElement('span');
                    name.textContent = item.name + (item.artist ? ' · ' + item.artist : '');
                    const dur = document.createElement('span');
                    dur.textContent = item.duration ? fmt(item.duration) : '';
                    li.append(name, dur);
                    list.appendChild(li);
                });
                $('next-up').style.display = list.children.length ? 'block' : 'none';
            }).catch(() => { $('next-up').style.display = 'none'; });
            refreshModes();
        }, 800);
    }

    function setupControls() {
        const act = op => () => { if (deviceId) api('/devices/' + deviceId + '/' + op, { method: 'POST' }).catch(e => status('Could not ' + op + ' (' + e.message + ')')); };
        $('prev').addEventListener('click', act('previous'));
        $('next').addEventListener('click', act('next'));
        $('playpause').addEventListener('click', () => {
            if (!current) return;
            if (audio.paused || pendingPlay) {
                if (current.live) audio.src = current.url;
                play();
            } else {
                audio.pause();
            }
            report();
        });
        $('vol').addEventListener('input', e => {
            audio.muted = false;
            audio.volume = e.target.value / 100;
        });
        $('shuffle').addEventListener('click', () => {
            api('/devices/' + deviceId + '/shuffle', { method: 'PUT', body: JSON.stringify({ shuffle: !modes.shuffle }) }).then(() => { refreshModes(); refreshQueue(); }).catch(() => {});
        });
        $('repeat').addEventListener('click', () => {
            const next = { off: 'all', all: 'one', one: 'off' }[modes.repeat] || 'off';
            api('/devices/' + deviceId + '/repeat', { method: 'PUT', body: JSON.stringify({ repeat: next }) }).then(refreshModes).catch(() => {});
        });
        document.addEventListener('keydown', e => {
            if (e.metaKey || e.ctrlKey || e.altKey || /^(INPUT|SELECT|TEXTAREA)$/.test(e.target.tagName) && e.target.type !== 'range') return;
            const live = current && current.live;
            const key = e.key.toLowerCase();
            if (key === ' ') { if (e.target.tagName === 'BUTTON') return; e.preventDefault(); $('playpause').click(); }
            else if (key === 'n') $('next').click();
            else if (key === 'p') $('prev').click();
            else if (key === 'm') $('mute').click();
            else if (key === 'arrowleft' && current && !live) { e.preventDefault(); audio.currentTime = Math.max(0, audio.currentTime - 10); }
            else if (key === 'arrowright' && current && !live) { e.preventDefault(); audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + 10); }
            else if (key === 'arrowup') { e.preventDefault(); audio.muted = false; audio.volume = Math.min(1, audio.volume + .05); }
            else if (key === 'arrowdown') { e.preventDefault(); audio.volume = Math.max(0, audio.volume - .05); }
        });
        refreshModes();
        $('max').addEventListener('click', () => { audio.muted = false; audio.volume = 1; });
        $('mute').addEventListener('click', () => { audio.muted = !audio.muted; updateUi(); });
        $('bar-wrap').addEventListener('click', e => {
            if (!current || current.live || !Number.isFinite(audio.duration)) return;
            const rect = $('bar').getBoundingClientRect();
            audio.currentTime = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width)) * audio.duration;
            report();
        });
        audio.addEventListener('timeupdate', updateUi);
        audio.addEventListener('durationchange', updateUi);
        audio.addEventListener('play', updateUi);
        audio.addEventListener('pause', updateUi);
        updateUi();
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

    function connectMqtt(info, tries) {
        // The MQTT library comes from a CDN and may load late or never: the player works by polling meanwhile.
        if (!window.mqtt) {
            if ((tries || 0) < 60) setTimeout(() => connectMqtt(info, (tries || 0) + 1), 1000);
            return;
        }
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
            status('Choose a player above to continue.');
            return new Promise(resolve => $('use').addEventListener('click', () => {
                $('choose').style.display = 'none';
                resolve(select.value ? parseInt(select.value, 10) : null);
            }, { once: true }));
        }).catch(() => null);
    }

    async function init() {
        window.__playerStarted = true;
        status('Starting…');
        $('name').value = store.get(NAME_KEY) || defaultName();
        status('Looking for players…');
        const existing = await chooseExisting();
        status('Registering…');
        const info = await register(existing);
        status('Registered, starting…');
        lastCommand = info.last_command_id || 0;
        setupMediaSession();
        connectMqtt(info);
        // The controls are a nicety: if they fail (for example a cached page without one of the elements),
        // the player must still receive its commands.
        try { setupControls(); if (!current) loadQuick(); } catch (e) { console.error('player controls', e); }

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

    // Start as soon as the page is parsed: DOMContentLoaded would also wait for the CDN script.
    const start = () => init().catch(e => status('Could not register: ' + e.message));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
