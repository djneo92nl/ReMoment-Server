// The Web Audio side of the stem mixer (docs/frontend/stem-mixer.md). No DOM, no Alpine.
//
// A deck is the four stems of one track:
//   4 x AudioBufferSourceNode (playbackRate = tempo) -> envelope -> stem gain -> sum -> SoundTouch -> deck gain -> master
// The sources start at the same audio-clock time, so the stems stay sample-aligned. A source's playbackRate changes
// tempo AND pitch; the SoundTouch worklet (playbackRate mirrored) puts the pitch back, and its `pitchSemitones` moves
// the key on purpose. One SoundTouch per deck, not per stem: the stems are summed first (gains are applied before it).

// SoundTouch is imported only when the browser has AudioWorklet: that exists in a secure context only (https or
// localhost), and a module that extends AudioWorkletNode throws on import without it. Without it the mixer still
// plays; tempo then moves the pitch along and the key shift is off (`pitchCorrection` is false).
import { beatAt, beatIndex, isDownbeat } from './beats.js';
import { wrapLoop } from './cues.js';
import { binPeaks, clamp, orderStems, stemGains } from './math.js';

const FADE = 0.006; // seconds of fade at a start/stop, so a seek or nudge does not click
const SMOOTH = 0.015; // seconds of smoothing on a fader
const PRE = 0.004; // a start is scheduled this much early and faded in by its beat, so the attack of a kick is not softened

/** The shared audio context and master volume of both decks, made on the first user gesture. */
export class MixerAudio {
    ctx = null;
    master = null;
    SoundTouchNode = null;
    pitchCorrection = false;

    async ensure() {
        if (!this.ctx) {
            this.ctx = new AudioContext({ latencyHint: 'playback' });
            if (typeof AudioWorkletNode !== 'undefined' && this.ctx.audioWorklet) {
                try {
                    const [{ SoundTouchNode }, { default: processorUrl }] = await Promise.all([
                        import('@soundtouchjs/audio-worklet'),
                        import('@soundtouchjs/audio-worklet/processor?url'),
                    ]);
                    await SoundTouchNode.register(this.ctx, processorUrl);
                    this.SoundTouchNode = SoundTouchNode;
                    this.pitchCorrection = true;
                } catch (e) {
                    console.warn('Pitch correction is not available:', e);
                }
            }
            this.master = this.ctx.createGain();
            this.master.connect(this.ctx.destination);
        }
        if (this.ctx.state === 'suspended') {
            await this.ctx.resume();
        }

        return this.ctx;
    }

    setMaster(value) {
        if (this.master) {
            this.master.gain.setTargetAtTime(clamp(value, 0, 1), this.ctx.currentTime, SMOOTH);
        }
    }

    close() {
        this.ctx?.close();
        this.ctx = null;
        this.master = null;
    }
}

export class Deck {
    /** @param {MixerAudio} audio */
    constructor(audio) {
        this.audio = audio;
        this.buffers = null;
        this.stems = [];
        this.sources = [];
        this.rate = 1;
        this.offset = 0; // seconds into the track at `anchor`
        this.anchor = 0; // audio-context time `offset` belongs to
        this.playing = false;
        this.duration = 0;
        this.onEnded = null;
        this.loop = null; // { start, end } in seconds of the track: the four sources loop there on their own
        this.next = null; // a jump or start scheduled for later: { sources, when, offset }
    }

    get loaded() {
        return this.buffers !== null;
    }

    /** Fetches and decodes the stems of the track (urls: { vocals, drums, bass, other, and guitar and piano when it has six }). */
    async load(urls) {
        const ctx = await this.audio.ensure();
        this.dispose();

        const names = orderStems(Object.keys(urls));
        const buffers = await Promise.all(names.map(async (stem) => {
            const response = await fetch(urls[stem]);
            if (!response.ok) {
                throw new Error(`The ${stem} stem could not be loaded (HTTP ${response.status}).`);
            }

            return ctx.decodeAudioData(await response.arrayBuffer());
        }));

        this.stems = names;
        this.buffers = Object.fromEntries(names.map((stem, i) => [stem, buffers[i]]));
        this.duration = Math.min(...buffers.map((b) => b.duration)); // the encoders may pad a stem by a few ms
        this.offset = 0;

        this.mix = ctx.createGain();
        this.stretch = this.audio.pitchCorrection ? new this.audio.SoundTouchNode({ context: ctx }) : ctx.createGain();
        if (this.audio.pitchCorrection) {
            this.stretch.pitch.value = 1;
        }
        this.out = ctx.createGain();
        this.analyser = ctx.createAnalyser();
        this.analyser.fftSize = 512;
        this.levelData = new Float32Array(this.analyser.fftSize);
        this.gains = Object.fromEntries(names.map((stem) => {
            const gain = ctx.createGain();
            gain.connect(this.mix);

            return [stem, gain];
        }));
        this.mix.connect(this.stretch);
        this.stretch.connect(this.out);
        this.out.connect(this.analyser);
        this.out.connect(this.audio.master);
        this.setRate(this.rate);
    }

    /** Waveform heights (0..1, `bins` of them) of each stem and of the full track (the stems added together). */
    waveforms(bins = 800) {
        if (!this.loaded) {
            return null;
        }
        const channels = (buffer) => Array.from({ length: buffer.numberOfChannels }, (_, c) => buffer.getChannelData(c));
        const all = this.stems.flatMap((stem) => channels(this.buffers[stem]));
        const peaks = Object.fromEntries(this.stems.map((stem) => [stem, binPeaks(channels(this.buffers[stem]), bins)]));
        // The full track is the sum of the stems per channel; added across channels too it is close enough for a picture.
        peaks.mix = binPeaks(all, bins, true);

        return peaks;
    }

    /** Where the deck is, in seconds of the track. */
    position() {
        if (!this.loaded) {
            return 0;
        }
        this.#settle();
        if (!this.playing) {
            return this.offset;
        }

        return this.positionAt(this.audio.ctx.currentTime);
    }

    /** Where the deck is (or will be, at the current rate) at audio-clock time `time`, in seconds of the track. */
    positionAt(time) {
        if (!this.loaded) {
            return 0;
        }
        if (!this.playing) {
            return this.offset;
        }

        return Math.min(this.duration, wrapLoop(this.#raw(time), this.loop));
    }

    /**
     * Audio-clock time of this playing deck's next beat (or, with `bar`, next bar line) on `grid`, at least `lead` seconds
     * from now; null when not playing.
     */
    nextBeatTime(grid, lead = 0.04, bar = false) {
        this.#settle();
        if (!this.playing) {
            return null;
        }
        const t0 = this.audio.ctx.currentTime + lead;
        const raw = this.#raw(t0);
        let k = Math.max(0, Math.ceil(beatIndex(grid, raw) - 1e-9));
        while (bar && !isDownbeat(grid, k)) k++;

        // On the unwrapped timeline: a loop that is a whole number of beats long repeats the same beats in wall time.
        return t0 + (beatAt(grid, k) - raw) / this.rate;
    }

    /**
     * Plays gain curves from audio-clock time `start` for `duration` seconds (a stem transition, D4): `stems` is a
     * Float32Array per stem, `fade` one for the deck's crossfader gain. They replace whatever the faders were set to, from now.
     */
    automate({ start, duration, stems = {}, fade = null }) {
        if (!this.gains) {
            return;
        }
        const now = this.audio.ctx.currentTime;
        const run = (param, curve) => {
            param.cancelScheduledValues(now);
            param.setValueAtTime(curve[0], now);
            param.setValueCurveAtTime(curve, Math.max(start, now), duration);
        };
        for (const [stem, curve] of Object.entries(stems)) {
            if (this.gains[stem]) run(this.gains[stem].gain, curve);
        }
        if (fade) {
            run(this.out.gain, fade);
        }
        this.automationStart = start;
    }

    /** Drops the curves of `automate` and holds the faders at `now` = `{ stems: {stem: gain}, fade }` (where the curves had got to). */
    cancelAutomation(now = { stems: {}, fade: null }) {
        if (!this.gains || this.automationStart === undefined) {
            return;
        }
        const t = this.audio.ctx.currentTime;
        const from = Math.min(this.automationStart, t);
        const hold = (param, value) => {
            param.cancelScheduledValues(from);
            if (value !== null && value !== undefined) param.setValueAtTime(value, t);
        };
        for (const stem of this.stems) hold(this.gains[stem].gain, now.stems[stem]);
        hold(this.out.gain, now.fade);
        this.automationStart = undefined;
    }

    play() {
        if (!this.loaded || this.playing) {
            return;
        }
        if (this.offset >= this.duration - 0.05) {
            this.offset = 0;
        }
        if (this.loop && this.offset >= this.loop.end) {
            this.offset = this.loop.start; // a loop set behind the playhead: start in it
        }
        this.#start(this.offset);
    }

    /** Starts at audio-clock time `when` from `offset` (seconds of the track): a quantized start. */
    playAt(when, offset = this.offset) {
        if (!this.loaded || this.playing) {
            return;
        }
        this.#start(clamp(offset, 0, Math.max(0, this.duration - 0.05)), Math.max(when, this.audio.ctx.currentTime + 0.03));
    }

    pause() {
        this.#settle();
        if (!this.playing) {
            return;
        }
        this.offset = this.position();
        this.#stop();
    }

    /** Jumps to `seconds`; with `when` (audio-clock time, at least 20 ms ahead) a playing deck makes the jump at that instant. */
    seek(seconds, when = null) {
        if (!this.loaded) {
            return;
        }
        this.#settle();
        const target = clamp(seconds, 0, Math.max(0, this.duration - 0.05));
        if (!this.playing) {
            this.offset = target;

            return;
        }
        const now = this.audio.ctx.currentTime;
        if (when !== null && when > now + 0.02 && !this.next) {
            this.#schedule(target, when);

            return;
        }
        this.#stop();
        this.offset = target;
        this.#start(target);
    }

    /** The loop of the four sources (`{ start, end }` in seconds of the track), or null to leave it. Playing on past its end jumps to its start. */
    setLoop(loop) {
        this.#settle();
        const next = loop && loop.end - loop.start > 0.01 ? { start: clamp(loop.start, 0, this.duration), end: clamp(loop.end, 0, this.duration) } : null;
        if (!this.playing) {
            this.loop = next;

            return;
        }
        const pos = this.position();
        if (this.next) {
            this.#stop();
            this.loop = next;
            this.#start(next && pos >= next.end ? next.start : pos);

            return;
        }
        // Re-anchor at the model's own position, then change the sources in place: they wrap by themselves.
        this.offset = pos;
        this.anchor = this.audio.ctx.currentTime;
        this.loop = next;
        for (const { source } of this.sources) {
            this.#applyLoop(source);
        }
        if (next && pos >= next.end) {
            this.#stop();
            this.#start(next.start);
        }
    }

    /**
     * Shifts the deck against the other one by `milliseconds` (to line up beats by ear). The restart takes 30 ms, so the
     * shift is counted from where the deck will be by then: the deck ends up exactly `milliseconds` away, not 30 ms less.
     */
    nudge(milliseconds) {
        const from = this.playing ? this.positionAt(this.audio.ctx.currentTime + 0.03) : this.position();
        this.seek(from + milliseconds / 1000);
    }

    /** Tempo as a ratio of the track's own (1 = unchanged); the pitch is corrected by SoundTouch. */
    setRate(rate) {
        const next = clamp(rate, 0.1, 8);
        this.#settle();
        if (this.playing) {
            this.offset = this.position();
            this.anchor = this.audio.ctx.currentTime;
            for (const { source } of [...this.sources, ...(this.next?.sources ?? [])]) {
                source.playbackRate.setValueAtTime(next, this.audio.ctx.currentTime);
            }
        }
        this.rate = next;
        if (this.stretch && this.audio.pitchCorrection) {
            this.stretch.playbackRate.value = next;
        }
    }

    /** Moves the key without changing the tempo: -12..12 semitones. */
    setSemitones(semitones) {
        if (this.stretch && this.audio.pitchCorrection) {
            this.stretch.pitchSemitones.value = clamp(semitones, -12, 12);
        }
    }

    /** Applies the faders, mute and solo of the four stems. */
    setStems(levels, muted, solo) {
        if (!this.gains) {
            return;
        }
        const gains = stemGains(levels, muted, solo);
        for (const stem of this.stems) {
            this.gains[stem].gain.setTargetAtTime(gains[stem], this.audio.ctx.currentTime, SMOOTH);
        }
    }

    /** The deck's share of the crossfader, 0..1. */
    setFade(gain) {
        this.out?.gain.setTargetAtTime(clamp(gain, 0, 1), this.audio.ctx.currentTime, SMOOTH);
    }

    /** RMS of what the deck sends out now, 0..1 (for a level bar). */
    level() {
        if (!this.analyser || !this.playing) {
            return 0;
        }
        this.analyser.getFloatTimeDomainData(this.levelData);
        let sum = 0;
        for (const v of this.levelData) {
            sum += v * v;
        }

        return Math.min(1, Math.sqrt(sum / this.levelData.length) * 2.5);
    }

    dispose() {
        this.#stop();
        for (const node of [this.mix, this.stretch, this.out, this.analyser, ...Object.values(this.gains ?? {})]) {
            node?.disconnect();
        }
        this.buffers = null;
        this.gains = this.mix = this.stretch = this.out = this.analyser = null;
        this.duration = 0;
        this.offset = 0;
        this.loop = null;
    }

    /** Elapsed track time on the unwrapped timeline (loops not applied) at audio-clock time `time`. */
    #raw(time) {
        return this.offset + Math.max(0, time - this.anchor) * this.rate;
    }

    #applyLoop(source) {
        source.loop = this.loop !== null;
        if (this.loop) {
            source.loopStart = this.loop.start;
            source.loopEnd = this.loop.end;
        }
    }

    /** Creates and schedules the four sources to play `offset` at audio time `when`; returns them. */
    #sources(offset, when) {
        const ctx = this.audio.ctx;
        const pre = Math.min(PRE, offset / this.rate); // cannot start before the start of the track
        const at = when - pre;

        return this.stems.map((stem) => {
            const source = ctx.createBufferSource();
            source.buffer = this.buffers[stem];
            source.playbackRate.value = this.rate;
            this.#applyLoop(source);
            const envelope = ctx.createGain();
            envelope.gain.setValueAtTime(0, at);
            envelope.gain.setTargetAtTime(1, at, FADE / 3);
            source.connect(envelope);
            envelope.connect(this.gains[stem]);
            source.start(at, offset - pre * this.rate);

            return { stem, source, envelope };
        });
    }

    #start(offset, when = this.audio.ctx.currentTime + 0.03) {
        // the same instant for all four, so they stay aligned
        this.sources = this.#sources(offset, when);
        this.anchor = when;
        this.offset = offset;
        this.playing = true;
        this.#watchEnd();
    }

    #watchEnd() {
        const first = this.sources[0]?.source;
        if (!first) {
            return;
        }
        first.onended = () => {
            // Only the end of the track counts: a seek or pause stops the sources too, and they are replaced by then.
            this.#settle();
            if (this.playing && this.sources[0]?.source === first) {
                this.offset = this.duration;
                this.#stop();
                this.onEnded?.();
            }
        };
    }

    /** A jump of a playing deck at `when`: the new sources start then, the old ones fade out by then, the clock switches in #settle. */
    #schedule(target, when) {
        const fadeAt = when - PRE;
        for (const { source, envelope } of this.sources) {
            source.onended = null;
            envelope.gain.cancelScheduledValues(fadeAt);
            envelope.gain.setTargetAtTime(0, fadeAt, FADE / 3);
            try {
                source.stop(when + 0.05);
            } catch {
                // already stopped
            }
        }
        this.next = { sources: this.#sources(target, when), when, offset: target };
    }

    /** Switches the clock over to a scheduled jump once its time has come. */
    #settle() {
        if (!this.next || !this.audio.ctx || this.audio.ctx.currentTime < this.next.when) {
            return;
        }
        const { sources, when, offset } = this.next;
        this.next = null;
        this.sources = sources;
        this.anchor = when;
        this.offset = offset;
        this.#watchEnd();
    }

    #stop() {
        if (!this.audio.ctx) {
            this.sources = [];
            this.next = null;
            this.playing = false;

            return;
        }
        const now = this.audio.ctx.currentTime;
        for (const { source, envelope } of [...this.sources, ...(this.next?.sources ?? [])]) {
            source.onended = null;
            envelope.gain.cancelScheduledValues(now);
            envelope.gain.setTargetAtTime(0, now, FADE / 3);
            try {
                source.stop(now + FADE * 3);
            } catch {
                // already stopped
            }
        }
        this.sources = [];
        this.next = null;
        this.playing = false;
    }
}
