// The Alpine component of /mixer (docs/frontend/stem-mixer.md): two decks, a crossfader and a track picker over the
// REST API. The audio objects live in this closure, not in the reactive state: Alpine wraps state in proxies, which
// Web Audio nodes do not survive.

import { STEMS, RATE_MAX, RATE_MIN, crossfade, decodedMegabytes, effectiveBpm, formatTime, shiftCamelot, syncRate } from './math.js';

const POLL_MS = 3000;
const ENGINE = () => import('./engine.js');

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
    levels: Object.fromEntries(STEMS.map((s) => [s, 1])),
    muted: Object.fromEntries(STEMS.map((s) => [s, false])),
    solo: Object.fromEntries(STEMS.map((s) => [s, false])),
    rate: 1,
    semitones: 0,
    pos: 0,
    dur: 0,
    playing: false,
    level: 0,
});

export default function mixer(config = {}) {
    const api = config.api ?? '/api';
    let audio = null; // MixerAudio
    let engine = null; // the engine module
    const decks = { a: null, b: null }; // Deck instances
    const polls = { a: null, b: null };
    let raf = null;

    const json = async (path, options = {}) => {
        const response = await fetch(`${api}${path}`, {
            ...options,
            headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}) },
        });

        return { status: response.status, body: await response.json().catch(() => ({})) };
    };

    return {
        STEMS,
        RATE_MIN,
        RATE_MAX,
        deck: { a: freshDeck(), b: freshDeck() },
        cross: 0.5,
        master: 0.9,
        query: '',
        found: [],
        similar: [],
        searching: false,
        pickerError: '',

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
            const bpm = effectiveBpm(d.bpm, d.rate);

            return bpm ? `${bpm.toFixed(1)} BPM` : `${d.rate >= 1 ? '+' : ''}${((d.rate - 1) * 100).toFixed(1)}%`;
        },
        keyLabel(side) {
            const d = this.deck[side];

            return d.camelot ? shiftCamelot(d.camelot, d.semitones) : d.key;
        },
        canSync(side) {
            const other = this.deck[side === 'a' ? 'b' : 'a'];

            return this.deck[side].bpm > 0 && other.bpm > 0;
        },

        // --- loading a track into a deck -------------------------------------------------

        async setTrack(side, track) {
            decks[side]?.dispose();
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
                d.megabytes = decodedMegabytes(body.duration, body.sample_rate);
                await this.load(side, body.stems);

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

        async load(side, stems) {
            const d = this.deck[side];
            d.status = 'loading';
            try {
                engine ??= await ENGINE();
                audio ??= new engine.MixerAudio();
                decks[side] = new engine.Deck(audio);
                decks[side].onEnded = () => {
                    this.deck[side].playing = false;
                };
                await decks[side].load(Object.fromEntries(STEMS.map((s) => [s, stems[s].url])));
                d.dur = decks[side].duration;
                d.status = 'ready';
                this.apply(side);
                this.applyCross();
                audio.setMaster(this.master);
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
                deck.play();
            }
            this.deck[side].playing = deck.playing;
            this.tick();
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

        nudge(side, milliseconds) {
            decks[side]?.nudge(milliseconds);
        },

        // --- sound ------------------------------------------------------------------------

        apply(side) {
            const d = this.deck[side];
            decks[side]?.setStems(d.levels, d.muted, d.solo);
        },

        toggle(side, kind, stem) {
            this.deck[side][kind][stem] = !this.deck[side][kind][stem];
            this.apply(side);
        },

        setLevel(side, stem, value) {
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

        /** Matches this deck's tempo to the other deck's current tempo (in half or double time when that is closer). */
        sync(side) {
            const other = this.deck[side === 'a' ? 'b' : 'a'];
            const target = effectiveBpm(other.bpm, other.rate);
            if (this.deck[side].bpm > 0 && target) {
                this.setRate(side, syncRate(this.deck[side].bpm, target));
            }
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
                if (any) {
                    raf = requestAnimationFrame(run);
                }
            };
            run();
        },
    };
}
