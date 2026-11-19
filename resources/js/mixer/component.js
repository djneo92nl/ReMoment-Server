// The Alpine component of /mixer (docs/frontend/stem-mixer.md): two decks, a crossfader and a track picker over the
// REST API. The audio objects live in this closure, not in the reactive state: Alpine wraps state in proxies, which
// Web Audio nodes do not survive.

import { beatPeriod, parseGrid, phaseShift, serializeGrid, setDownbeatAt, shiftGrid, snapToBar, snapToBeat, stemsFingerprint, ticksPath } from './beats.js';
import { HOT_CUES, LOOP_BEATS, beatLabel, beatLoop, loopFromPoints, sanitizeCues, scaleLoop, snapPosition } from './cues.js';
import { BARS, BEATS_PER_BAR, PRESETS, buildPlan, curvePoints, fadeGains, planAt, planSeconds, sampleCurve } from './transitions.js';
import { orderStems, RATE_MAX, RATE_MIN, crossfade, decodedMegabytes, effectiveBpm, formatTime, peaksPath, shiftCamelot, syncRate } from './math.js';

const POLL_MS = 3000;
export const WAVE_BINS = 800; // the width of the waveform paths (viewBox units)
const ENGINE = () => import('./engine.js');
const BEATS = () => import('./beats-client.js');
const GRID_KEY = 'remoment.mixer.grid.v2.'; // v2: the analysis changed (v1 remembered "no grid" for real tracks)
const CUES_KEY = 'remoment.mixer.cues.v1.';

// localStorage is per browser and can be absent or full: everything that touches it is allowed to fail.
const stored = (key) => {
    try {
        return JSON.parse(localStorage.getItem(key));
    } catch {
        return null;
    }
};
const store = (key, value) => {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // private window or no space: the cue just is not remembered
    }
};

const freshDeck = () => ({
    track: null, // SearchTrack of the REST API
    status: 'empty', // empty | checking | needs_stems | processing | loading | ready | error
    error: '',
    jobState: null,
    available: true, // the stems service can separate a track now
    estimate: null, // seconds, once requested
    bpm: null,
    key: null,
    camelot: null,
    format: null,
    megabytes: 0,
    stems: [], // the stems this track has: four, or six with guitar and piano
    levels: {},
    muted: {},
    solo: {},
    rate: 1,
    semitones: 0,
    pos: 0,
    dur: 0,
    playing: false,
    level: 0,
    wave: null, // SVG path per stem and `mix` (the full track), see peaksPath
    grid: null, // { bpm, offset, downbeat, lock, bar } of beats.js, or null
    gridState: 'none', // none | analysing | ready | nogrid | error
    ticks: { beat: '', bar: '' }, // SVG paths of the beat ticks on the waveforms
    quantize: true, // with a grid: starts, cues and loops land on the beat
    cue: null, // the main cue, seconds
    hot: Array.from({ length: HOT_CUES }, () => null), // hot cues, seconds
    markIn: null, // a loop-in point waiting for its out point
    loop: null, // { start, end, beats } while a loop is on
});

export default function mixer(config = {}) {
    const api = config.api ?? '/api';
    let audio = null; // MixerAudio
    let engine = null; // the engine module
    const decks = { a: null, b: null }; // Deck instances
    const polls = { a: null, b: null };
    const prints = { a: null, b: null }; // fingerprint of each deck's stems: the key of its grid and cue caches
    const previews = { a: false, b: false }; // a main-cue preview is being held
    let trans = null; // the running or armed stem transition (D4): { plan, out, inn, start, beatSeconds, startedIn }
    let raf = null;
    const other = (side) => (side === 'a' ? 'b' : 'a');

    const json = async (path, options = {}) => {
        const response = await fetch(`${api}${path}`, {
            ...options,
            headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}) },
        });

        return { status: response.status, body: await response.json().catch(() => ({})) };
    };

    return {
        RATE_MIN,
        RATE_MAX,
        LOOP_BEATS,
        BARS,
        PRESETS,
        mix: { preset: 'blend', bars: 16, state: 'idle', info: '', message: '' }, // the Mix now panel: state idle | armed | running
        HOT_CUES,
        beatLabel,
        deck: { a: freshDeck(), b: freshDeck() },
        cross: 0.5,
        master: 0.9,
        query: '',
        found: [],
        similar: [],
        searching: false,
        pickerError: '',
        pitchCorrection: true, // false in a browser without AudioWorklet (a page over plain http): tempo moves the pitch, no key shift

        init() {
            for (const side of ['a', 'b']) {
                if (config.tracks?.[side]) {
                    this.setTrack(side, config.tracks[side]);
                }
            }
            window.addEventListener('beforeunload', () => this.destroy());
        },

        destroy() {
            cancelAnimationFrame(raf);
            for (const side of ['a', 'b']) {
                clearTimeout(polls[side]);
                decks[side]?.dispose();
            }
            audio?.close();
        },

        // --- formatting for the view ---------------------------------------------------

        time: formatTime,
        percent: (value) => `${Math.round(value * 100)}%`,
        tempoLabel(side) {
            const d = this.deck[side];
            const bpm = effectiveBpm(d.grid?.bpm ?? d.bpm, d.rate);

            return bpm ? `${bpm.toFixed(1)} BPM` : `${d.rate >= 1 ? '+' : ''}${((d.rate - 1) * 100).toFixed(1)}%`;
        },
        keyLabel(side) {
            const d = this.deck[side];

            return d.camelot ? shiftCamelot(d.camelot, d.semitones) : d.key;
        },
        canSync(side) {
            const other = this.deck[side === 'a' ? 'b' : 'a'];

            return (this.deck[side].grid?.bpm ?? this.deck[side].bpm) > 0 && (other.grid?.bpm ?? other.bpm) > 0;
        },
        gridLabel(side) {
            const d = this.deck[side];
            if (d.gridState === 'analysing') return 'Finding the beats…';
            if (d.gridState === 'nogrid') return 'No steady beat, beat features are off';
            if (d.gridState === 'error') return 'The beats could not be found';
            if (!d.grid) return '';
            const beat = (Math.floor((d.pos - d.grid.offset) / beatPeriod(d.grid)) - d.grid.downbeat) % 4;

            return `${d.grid.bpm.toFixed(2)} BPM · beat ${((beat % 4) + 4) % 4 + 1} of 4`;
        },
        /** Beat features need a grid on the deck. */
        beatsOn(side) {
            return this.deck[side].grid !== null;
        },
        /** Where a loop or a cue sits on the waveform, as a percentage. */
        at(side, seconds) {
            const d = this.deck[side];

            return d.dur > 0 && seconds !== null ? Math.min(100, Math.max(0, (seconds / d.dur) * 100)) : 0;
        },

        // --- loading a track into a deck -------------------------------------------------

        async setTrack(side, track) {
            decks[side]?.dispose();
            prints[side] = null;
            clearTimeout(polls[side]);
            this.deck[side] = { ...freshDeck(), track, status: 'checking' };
            if (side === 'a') {
                this.loadSimilar(track.id);
            }
            await this.prepare(side);
        },

        /** Reads the kept stems of the deck's track and loads them, or says what is missing. */
        async prepare(side) {
            const d = this.deck[side];
            const { status, body } = await json(`/library/tracks/${d.track.id}/stems`);

            if (status === 200) {
                d.bpm = body.bpm;
                d.key = body.key ? `${body.key}${body.mode === 'minor' ? 'm' : ''}` : null;
                d.camelot = body.camelot;
                d.format = body.format;
                prints[side] = stemsFingerprint(d.track.id, body);
                d.stems = orderStems(Object.keys(body.stems));
                d.levels = Object.fromEntries(d.stems.map((s) => [s, 1]));
                d.muted = Object.fromEntries(d.stems.map((s) => [s, false]));
                d.solo = Object.fromEntries(d.stems.map((s) => [s, false]));
                d.megabytes = decodedMegabytes(body.duration, body.sample_rate, d.stems.length);
                await this.load(side, body.stems, body);

                return;
            }
            if (status === 404 && body.error === 'not_ready') {
                d.jobState = body.state;
                d.status = ['queued', 'running'].includes(body.state) ? 'processing' : 'needs_stems';
                d.error = body.state === 'failed' ? body.message : '';
                d.status === 'processing' ? this.poll(side) : this.estimateFor(side);

                return;
            }
            d.status = 'error';
            d.error = status === 422 && body.error === 'no_file'
                ? 'This track has no audio file of its own on this server, so it has no stems.'
                : body.message || 'The stems could not be read.';
        },

        async load(side, stems, answer = null) {
            const d = this.deck[side];
            d.status = 'loading';
            try {
                engine ??= await ENGINE();
                audio ??= new engine.MixerAudio();
                await audio.ensure();
                this.pitchCorrection = audio.pitchCorrection;
                decks[side] = new engine.Deck(audio);
                decks[side].onEnded = () => {
                    this.deck[side].playing = false;
                };
                await decks[side].load(Object.fromEntries(d.stems.map((s) => [s, stems[s].url])));
                d.dur = decks[side].duration;
                const peaks = decks[side].waveforms(WAVE_BINS);
                d.wave = Object.fromEntries(Object.entries(peaks).map(([key, values]) => [key, peaksPath(values)]));
                d.status = 'ready';
                this.apply(side);
                this.applyCross();
                audio.setMaster(this.master);
                this.loadCues(side);
                this.findGrid(side, answer?.bpm ?? null);
            } catch (e) {
                decks[side]?.dispose();
                d.status = 'error';
                d.error = e?.message || 'The stems could not be loaded.';
            }
        },

        async estimateFor(side) {
            const { status, body } = await json(`/library/tracks/${this.deck[side].track.id}/deep-analysis`, { method: 'GET' });
            if (status === 200) {
                this.deck[side].available = Boolean(body.kinds?.stem_audio?.available);
            }
        },

        async requestStems(side) {
            const d = this.deck[side];
            const { status, body } = await json(`/library/tracks/${d.track.id}/deep-analysis`, { method: 'POST', body: JSON.stringify({ kinds: ['stem_audio'] }) });

            if (status === 409) {
                d.status = 'error';
                d.error = 'The stems service is not available on this server.';

                return;
            }
            if (status >= 400) {
                d.status = 'error';
                d.error = body.message || 'The stems could not be requested.';

                return;
            }
            d.estimate = body.estimate?.stem_audio_seconds ?? null;
            d.status = body.status === 'already_done' ? 'checking' : 'processing';
            body.status === 'already_done' ? await this.prepare(side) : this.poll(side);
        },

        poll(side) {
            clearTimeout(polls[side]);
            polls[side] = setTimeout(async () => {
                const d = this.deck[side];
                if (d.status !== 'processing') {
                    return;
                }
                const { status, body } = await json(`/library/tracks/${d.track.id}/deep-analysis`);
                const state = body.kinds?.stem_audio?.state;
                if (status === 200 && state === 'done') {
                    d.status = 'checking';
                    await this.prepare(side);
                } else if (status === 200 && state === 'failed') {
                    d.status = 'error';
                    d.error = body.kinds.stem_audio.message || 'Separating the stems failed.';
                } else {
                    this.poll(side);
                }
            }, POLL_MS);
        },

        unload(side) {
            decks[side]?.dispose();
            decks[side] = null;
            prints[side] = null;
            clearTimeout(polls[side]);
            this.deck[side] = freshDeck();
        },

        // --- the picker -------------------------------------------------------------------

        async loadSimilar(trackId) {
            this.similar = [];
            const { status, body } = await json(`/library/tracks/${trackId}/mixes-with`);
            this.similar = status === 200 ? body.data.slice(0, 12) : [];
        },

        async search() {
            this.pickerError = '';
            if (this.query.trim().length < 2) {
                this.found = [];

                return;
            }
            this.searching = true;
            const { status, body } = await json(`/library/search?q=${encodeURIComponent(this.query.trim())}`);
            this.searching = false;
            if (status === 200) {
                this.found = body.tracks;
            } else {
                this.pickerError = 'The search failed.';
            }
        },

        // --- transport --------------------------------------------------------------------

        togglePlay(side) {
            const deck = decks[side];
            if (!deck?.loaded) {
                return;
            }
            audio.ensure(); // resumes a context the browser suspended
            if (deck.playing) {
                deck.pause();
            } else {
                this.startDeck(side, deck.position());
            }
            this.deck[side].playing = deck.playing;
            this.tick();
        },

        /**
         * Starts a stopped deck at `position`. With a grid and quantize on, while the other deck plays on a grid, the start
         * lands on the other deck's next beat and the position moves to the nearest beat of this deck's own grid.
         */
        startDeck(side, position) {
            const deck = decks[side];
            const d = this.deck[side];
            const o = this.deck[other(side)];
            const reference = decks[other(side)];
            if (d.quantize && d.grid && o.grid && reference?.playing) {
                const when = reference.nextBeatTime(o.grid);
                if (when !== null) {
                    deck.playAt(when, snapToBeat(d.grid, position, 'nearest'));
                    d.playing = deck.playing;

                    return;
                }
            }
            deck.seek(position);
            deck.play();
            d.playing = deck.playing;
        },

        /** Starts both decks at their current positions, in the same instant. */
        playBoth() {
            const both = ['a', 'b'].filter((s) => decks[s]?.loaded);
            const anyPlaying = both.some((s) => decks[s].playing);
            for (const side of both) {
                if (anyPlaying) {
                    decks[side].pause();
                } else {
                    decks[side].play();
                }
                this.deck[side].playing = decks[side].playing;
            }
            this.tick();
        },

        seek(side, seconds) {
            decks[side]?.seek(Number(seconds));
            this.deck[side].pos = decks[side]?.position() ?? 0;
        },

        /** A click on the waveforms seeks to that spot. */
        seekFromClick(side, event) {
            const box = event.currentTarget.getBoundingClientRect();
            if (box.width > 0) {
                this.seek(side, ((event.clientX - box.left) / box.width) * this.deck[side].dur);
            }
        },

        playedClip(side) {
            const d = this.deck[side];

            return `inset(0 ${d.dur > 0 ? Math.max(0, 100 - (d.pos / d.dur) * 100).toFixed(2) : 100}% 0 0)`;
        },

        nudge(side, milliseconds) {
            decks[side]?.nudge(milliseconds);
        },

        // --- sound ------------------------------------------------------------------------

        apply(side) {
            const d = this.deck[side];
            decks[side]?.setStems(d.levels, d.muted, d.solo);
        },

        toggle(side, kind, stem) {
            this.cancelTransition();
            this.deck[side][kind][stem] = !this.deck[side][kind][stem];
            this.apply(side);
        },

        setLevel(side, stem, value) {
            this.cancelTransition();
            this.deck[side].levels[stem] = Number(value);
            this.apply(side);
        },

        setRate(side, value) {
            const rate = Number(value);
            this.deck[side].rate = rate;
            decks[side]?.setRate(rate);
        },

        setSemitones(side, value) {
            const semitones = Number(value);
            this.deck[side].semitones = semitones;
            decks[side]?.setSemitones(semitones);
        },

        /**
         * Matches this deck's tempo to the other deck's current tempo (in half or double time when that is closer) and, when
         * both decks play on a grid, moves this deck by up to half a beat so the beats coincide.
         */
        sync(side) {
            const me = this.deck[side];
            const them = this.deck[other(side)];
            const target = effectiveBpm(them.grid?.bpm ?? them.bpm, them.rate);
            const own = me.grid?.bpm ?? me.bpm;
            if (!(own > 0) || !target) {
                return;
            }
            this.setRate(side, syncRate(own, target));
            if (me.grid && them.grid && decks[side]?.playing && decks[other(side)]?.playing) {
                this.alignPhase(side);
            }
        },

        /** Seeks this playing deck, at a moment a little ahead, to where its beat phase equals the other deck's then. */
        alignPhase(side) {
            const mine = decks[side];
            const theirs = decks[other(side)];
            const t = audio.ctx.currentTime + 0.06;
            const posS = mine.positionAt(t);
            const delta = phaseShift({
                gridS: this.deck[side].grid, posS, rateS: this.deck[side].rate,
                gridO: this.deck[other(side)].grid, posO: theirs.positionAt(t), rateO: this.deck[other(side)].rate,
            });
            if (Math.abs(delta) > 0.0005) {
                mine.seek(posS + delta, t);
            }
        },

        /** A whole beat forwards or back (-1, +1): the coarse companion of the 20 ms nudge. Needs a tempo (grid, or the analysed BPM). */
        shiftBeat(side, direction) {
            const d = this.deck[side];
            const bpm = d.grid?.bpm ?? d.bpm;
            if (bpm > 0 && decks[side]) {
                decks[side].nudge((direction * 60000) / bpm);
                d.pos = decks[side].position();
            }
        },

        /** The crossfader moved by hand. */
        moveCross(value) {
            this.cancelTransition();
            this.cross = Number(value);
            this.applyCross();
        },

        applyCross() {
            const { a, b } = crossfade(this.cross);
            decks.a?.setFade(a);
            decks.b?.setFade(b);
        },

        setMaster(value) {
            this.master = Number(value);
            audio?.setMaster(this.master);
        },

        // --- the beat grid (D1) -----------------------------------------------------------

        /** Finds the grid of the deck's track (from the browser's cache, or by analysing the drums and bass in a worker). */
        async findGrid(side, seedBpm) {
            const deck = decks[side];
            const print = prints[side];
            const cached = print ? parseGrid(stored(GRID_KEY + print)) : undefined;
            if (cached !== undefined) {
                this.setGrid(side, cached);

                return;
            }
            this.deck[side].gridState = 'analysing';
            try {
                const { findGrid } = await BEATS();
                const result = await findGrid(deck.buffers, seedBpm);
                if (decks[side] !== deck || prints[side] !== print) {
                    return; // another track was loaded meanwhile
                }
                store(GRID_KEY + print, serializeGrid(result.grid));
                this.setGrid(side, result.grid);
            } catch (e) {
                console.warn('The beat grid could not be computed:', e);
                if (decks[side] === deck) {
                    this.deck[side].gridState = 'error';
                }
            }
        },

        /** Forgets the remembered grid of the deck's track and analyses it again. */
        retryGrid(side) {
            const print = prints[side];
            try {
                if (print) localStorage.removeItem(GRID_KEY + print);
            } catch {
                // nothing stored, or no storage
            }
            this.deck[side].grid = null;
            this.deck[side].ticks = { beat: '', bar: '' };
            this.findGrid(side, this.deck[side].bpm);
        },

        setGrid(side, grid) {
            const d = this.deck[side];
            d.grid = grid;
            d.gridState = grid ? 'ready' : 'nogrid';
            if (!grid && d.loop?.beats) {
                this.exitLoop(side);
            }
            this.drawGrid(side);
        },

        drawGrid(side) {
            const d = this.deck[side];
            d.ticks = ticksPath(d.grid, d.dur, WAVE_BINS);
        },

        saveGrid(side) {
            if (prints[side]) {
                store(GRID_KEY + prints[side], serializeGrid(this.deck[side].grid));
            }
        },

        /** Moves the grid against the audio by `milliseconds` (to fix a grid that sits a little early or late). */
        nudgeGrid(side, milliseconds) {
            const d = this.deck[side];
            if (d.grid) {
                d.grid = shiftGrid(d.grid, milliseconds / 1000);
                this.drawGrid(side);
                this.saveGrid(side);
            }
        },

        /** Makes the beat nearest to the playhead the first beat of a bar. */
        setDownbeat(side) {
            const d = this.deck[side];
            if (d.grid && decks[side]) {
                d.grid = setDownbeatAt(d.grid, decks[side].position());
                this.drawGrid(side);
                this.saveGrid(side);
            }
        },

        // --- cues and loops (D3) -----------------------------------------------------------

        loadCues(side) {
            const d = this.deck[side];
            const cues = sanitizeCues(prints[side] ? stored(CUES_KEY + prints[side]) : null, d.dur);
            d.cue = cues.cue;
            d.hot = cues.hot;
        },

        saveCues(side) {
            const d = this.deck[side];
            if (prints[side]) {
                store(CUES_KEY + prints[side], { cue: d.cue, hot: [...d.hot] });
            }
        },

        /** A position as the deck keeps it: on a beat with a grid and quantize on. */
        snap(side, seconds) {
            const d = this.deck[side];

            return snapPosition(d.grid, seconds, d.quantize);
        },

        /**
         * The main cue. Playing: back to the cue and stop. Stopped away from the cue: set it here. Stopped on the cue: play
         * from it while the button is held (the release returns to it).
         */
        cuePress(side) {
            const deck = decks[side];
            const d = this.deck[side];
            if (!deck?.loaded) {
                return;
            }
            audio.ensure();
            const pos = deck.position();
            if (deck.playing) {
                deck.pause();
                if (d.cue === null) {
                    d.cue = this.snap(side, pos);
                    this.saveCues(side);
                }
                deck.seek(d.cue);
                d.playing = false;
            } else if (d.cue === null || Math.abs(pos - d.cue) > 0.03) {
                d.cue = this.snap(side, pos);
                deck.seek(d.cue);
                this.saveCues(side);
            } else {
                previews[side] = true;
                this.startDeck(side, d.cue);
                this.tick();
            }
            d.pos = deck.position();
        },

        cueRelease(side) {
            if (!previews[side]) {
                return;
            }
            previews[side] = false;
            const deck = decks[side];
            const d = this.deck[side];
            deck.pause();
            deck.seek(d.cue);
            d.playing = false;
            d.pos = deck.position();
        },

        /** A hot cue: empty, it is set here; set, it jumps there (on the next beat while quantized and playing), or starts the deck from there. */
        hotCue(side, slot) {
            const deck = decks[side];
            const d = this.deck[side];
            if (!deck?.loaded) {
                return;
            }
            audio.ensure();
            if (d.hot[slot] === null) {
                d.hot[slot] = this.snap(side, deck.position());
                this.saveCues(side);

                return;
            }
            const target = d.hot[slot];
            if (deck.playing) {
                deck.seek(target, d.quantize && d.grid ? deck.nextBeatTime(d.grid) : null);
            } else {
                this.startDeck(side, target);
                this.tick();
            }
        },

        clearHot(side, slot) {
            this.deck[side].hot[slot] = null;
            this.saveCues(side);
        },

        loopIn(side) {
            const deck = decks[side];
            if (!deck?.loaded) {
                return;
            }
            this.exitLoop(side);
            this.deck[side].markIn = this.snap(side, deck.position());
        },

        /** Closes the loop at the playhead; playback returns to the loop-in point. */
        loopOut(side) {
            const d = this.deck[side];
            const deck = decks[side];
            if (!deck?.loaded || d.markIn === null) {
                return;
            }
            const loop = loopFromPoints(d.markIn, this.snap(side, deck.position()), d.dur);
            if (loop) {
                this.engageLoop(side, loop);
            }
        },

        /** A loop of `beats` beats from the nearest beat; pressing the active one again leaves the loop. Needs a grid. */
        beatLoop(side, beats) {
            const d = this.deck[side];
            const deck = decks[side];
            if (!deck?.loaded || !d.grid) {
                return;
            }
            if (d.loop?.beats === beats) {
                this.exitLoop(side);

                return;
            }
            const loop = beatLoop(d.grid, deck.position(), beats, d.dur, d.quantize);
            if (loop) {
                this.engageLoop(side, loop);
            }
        },

        /** The active loop twice as long (2) or half as long (0.5). */
        scaleLoop(side, factor) {
            const d = this.deck[side];
            const loop = d.loop ? scaleLoop(d.loop, factor, d.dur) : null;
            if (loop) {
                this.engageLoop(side, loop);
            }
        },

        engageLoop(side, loop) {
            const d = this.deck[side];
            d.loop = loop;
            d.markIn = null;
            decks[side].setLoop({ start: loop.start, end: loop.end });
            d.pos = decks[side].position();
            if (decks[side].playing) {
                this.tick();
            }
        },

        exitLoop(side) {
            const d = this.deck[side];
            if (d.loop) {
                decks[side]?.setLoop(null);
            }
            d.loop = null;
            d.markIn = null;
        },

        // --- stem transitions (D4) --------------------------------------------------------

        /**
         * Runs a transition preset from the outgoing deck (the one that plays, or when both do the one the crossfader is on)
         * to the other, starting on the outgoing deck's next bar line: the faders and the crossfader move over the bars as
         * Web Audio gain curves and stay where they end. Any fader or the crossfader grabbed by hand cancels it.
         */
        mixNow() {
            this.mix.message = '';
            const sides = ['a', 'b'];
            if (!sides.every((s) => decks[s]?.loaded && this.deck[s].status === 'ready')) {
                this.mix.message = 'Load a track into both decks first.';

                return;
            }
            if (!sides.every((s) => this.deck[s].grid)) {
                this.mix.message = 'Mix now needs a beat grid on both decks.';

                return;
            }
            const playing = sides.filter((s) => decks[s].playing);
            if (!playing.length) {
                this.mix.message = 'Start the deck that plays out first.';

                return;
            }
            this.cancelTransition();
            audio.ensure();
            const out = playing.length === 1 ? playing[0] : this.cross <= 0.5 ? 'a' : 'b';
            const inn = other(out);
            const dOut = this.deck[out];
            const dIn = this.deck[inn];

            this.sync(inn); // tempo (and phase when it already plays)
            const start = decks[out].nextBeatTime(dOut.grid, 0.15, true);
            const beatSeconds = planSeconds(1, dOut.grid.bpm, dOut.rate);
            const plan = buildPlan({
                preset: this.mix.preset, bars: Number(this.mix.bars), outStems: dOut.stems, inStems: dIn.stems,
                outLevels: { ...dOut.levels }, inLevels: { ...dIn.levels }, cross0: out === 'a' ? this.cross : 1 - this.cross,
            });
            const n = curvePoints(plan.beats);
            const duration = plan.beats * beatSeconds;
            const share = sampleCurve(plan.cross, plan.beats, n); // how far towards the incoming deck
            const curves = (group) => Object.fromEntries(Object.entries(group).map(([stem, keys]) => [stem, sampleCurve(keys, plan.beats, n)]));
            const fade = (pick) => Float32Array.from(share, (x) => fadeGains(x)[pick]);
            decks[out].automate({ start, duration, stems: curves(plan.out), fade: fade('out') });
            decks[inn].automate({ start, duration, stems: curves(plan.in), fade: fade('in') });

            let startedIn = null;
            if (!decks[inn].playing) {
                startedIn = start + plan.inStartBeat * beatSeconds;
                decks[inn].playAt(startedIn, snapToBar(dIn.grid, decks[inn].position()));
                dIn.playing = true;
            }
            trans = { plan, out, inn, start, beatSeconds, startedIn };
            this.mix.state = 'armed';
            this.tick();
        },

        /** The faders and crossfader of the UI follow the plan; the audio plays its own curves. */
        followTransition() {
            if (!trans) {
                return;
            }
            const now = audio.ctx.currentTime;
            if (now < trans.start) {
                this.mix.info = `Starts on the next bar, in ${(trans.start - now).toFixed(1)} s`;

                return;
            }
            const beat = (now - trans.start) / trans.beatSeconds;
            if (beat >= trans.plan.beats) {
                this.applyPlanAt(trans.plan.beats);
                this.endTransition();

                return;
            }
            this.mix.state = 'running';
            this.mix.info = `Bar ${Math.floor(beat / BEATS_PER_BAR) + 1} of ${trans.plan.bars}`;
            this.applyPlanAt(beat);
        },

        /** Puts the sliders where the plan is at `beat` (the audio is on its curves already). */
        applyPlanAt(beat) {
            const at = planAt(trans.plan, beat);
            Object.assign(this.deck[trans.out].levels, at.out);
            Object.assign(this.deck[trans.inn].levels, at.in);
            this.cross = trans.out === 'a' ? at.cross : 1 - at.cross;
        },

        endTransition() {
            for (const side of ['a', 'b']) {
                if (decks[side]) decks[side].automationStart = undefined;
            }
            trans = null;
            this.mix.state = 'idle';
            this.mix.info = '';
        },

        /** Stops the transition where it is: the faders stay at their values now. Also called by any fader moved by hand. */
        cancelTransition() {
            if (!trans) {
                return;
            }
            const now = audio.ctx.currentTime;
            const beat = Math.max(0, (now - trans.start) / trans.beatSeconds);
            const at = planAt(trans.plan, Math.min(beat, trans.plan.beats));
            this.applyPlanAt(Math.min(beat, trans.plan.beats));
            decks[trans.out].cancelAutomation({ stems: at.out, fade: fadeGains(at.cross).out });
            decks[trans.inn].cancelAutomation({ stems: at.in, fade: fadeGains(at.cross).in });
            if (trans.startedIn && now < trans.startedIn) {
                decks[trans.inn].pause(); // it was only waiting for its bar line
                this.deck[trans.inn].playing = false;
            }
            this.endTransition();
        },

        // --- the clock --------------------------------------------------------------------

        tick() {
            cancelAnimationFrame(raf);
            const run = () => {
                let any = false;
                for (const side of ['a', 'b']) {
                    const deck = decks[side];
                    if (deck?.loaded) {
                        const d = this.deck[side];
                        d.pos = deck.position();
                        d.playing = deck.playing;
                        d.level = deck.level();
                        any ||= deck.playing;
                    }
                }
                this.followTransition();
                if (any || trans) {
                    raf = requestAnimationFrame(run);
                }
            };
            run();
        },
    };
}
