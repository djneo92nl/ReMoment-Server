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
import { STEMS, binPeaks, clamp, stemGains } from './math.js';

const FADE = 0.006; // seconds of fade at a start/stop, so a seek or nudge does not click
const SMOOTH = 0.015; // seconds of smoothing on a fader

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
        this.sources = [];
        this.rate = 1;
        this.offset = 0; // seconds into the track at `anchor`
        this.anchor = 0; // audio-context time `offset` belongs to
        this.playing = false;
        this.duration = 0;
        this.onEnded = null;
    }

    get loaded() {
        return this.buffers !== null;
    }

    /** Fetches and decodes the four stems (urls: { vocals, drums, bass, other }). */
    async load(urls) {
        const ctx = await this.audio.ensure();
        this.dispose();

        const buffers = await Promise.all(STEMS.map(async (stem) => {
            const response = await fetch(urls[stem]);
            if (!response.ok) {
                throw new Error(`The ${stem} stem could not be loaded (HTTP ${response.status}).`);
            }

            return ctx.decodeAudioData(await response.arrayBuffer());
        }));

        this.buffers = Object.fromEntries(STEMS.map((stem, i) => [stem, buffers[i]]));
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
        this.gains = Object.fromEntries(STEMS.map((stem) => {
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
        const all = STEMS.flatMap((stem) => channels(this.buffers[stem]));
        const peaks = Object.fromEntries(STEMS.map((stem) => [stem, binPeaks(channels(this.buffers[stem]), bins)]));
        // The full track is the sum of the stems per channel; added across channels too it is close enough for a picture.
        peaks.mix = binPeaks(all, bins, true);

        return peaks;
    }

    /** Where the deck is, in seconds of the track. */
    position() {
        if (!this.loaded) {
            return 0;
        }
        if (!this.playing) {
            return this.offset;
        }

        return Math.min(this.duration, this.offset + (this.audio.ctx.currentTime - this.anchor) * this.rate);
    }

    play() {
        if (!this.loaded || this.playing) {
            return;
        }
        if (this.offset >= this.duration - 0.05) {
            this.offset = 0;
        }
        this.#start(this.offset);
    }

    pause() {
        if (!this.playing) {
            return;
        }
        this.offset = this.position();
        this.#stop();
    }

    seek(seconds) {
        if (!this.loaded) {
            return;
        }
        const target = clamp(seconds, 0, Math.max(0, this.duration - 0.05));
        const wasPlaying = this.playing;
        this.#stop();
        this.offset = target;
        if (wasPlaying) {
            this.#start(target);
        }
    }

    /** Shifts the deck against the other one by `milliseconds` (to line up beats by ear). */
    nudge(milliseconds) {
        this.seek(this.position() + milliseconds / 1000);
    }

    /** Tempo as a ratio of the track's own (1 = unchanged); the pitch is corrected by SoundTouch. */
    setRate(rate) {
        const next = clamp(rate, 0.1, 8);
        if (this.playing) {
            this.offset = this.position();
            this.anchor = this.audio.ctx.currentTime;
            for (const { source } of this.sources) {
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
        for (const stem of STEMS) {
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
    }

    #start(offset) {
        const ctx = this.audio.ctx;
        const when = ctx.currentTime + 0.03; // the same instant for all four, so they stay aligned
        this.sources = STEMS.map((stem) => {
            const source = ctx.createBufferSource();
            source.buffer = this.buffers[stem];
            source.playbackRate.value = this.rate;
            const envelope = ctx.createGain();
            envelope.gain.setValueAtTime(0, when);
            envelope.gain.setTargetAtTime(1, when, FADE / 3);
            source.connect(envelope);
            envelope.connect(this.gains[stem]);
            source.start(when, offset);

            return { stem, source, envelope };
        });
        this.anchor = when;
        this.offset = offset;
        this.playing = true;

        const [{ source: first }] = this.sources;
        first.onended = () => {
            // Only the end of the track counts: a seek or pause stops the sources too, and they are replaced by then.
            if (this.playing && this.sources[0]?.source === first) {
                this.offset = this.duration;
                this.#stop();
                this.onEnded?.();
            }
        };
    }

    #stop() {
        if (!this.audio.ctx) {
            this.sources = [];
            this.playing = false;

            return;
        }
        const now = this.audio.ctx.currentTime;
        for (const { source, envelope } of this.sources) {
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
        this.playing = false;
    }
}
