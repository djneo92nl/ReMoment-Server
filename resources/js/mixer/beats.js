// Beat grid of a track, found in the browser (docs/frontend/dj-mode.md, phase D1). Pure functions, no DOM and no Web
// Audio, so `node --test tests/Js` checks them on synthetic click tracks and the same code runs in a Web Worker.
//
// A grid is `{ bpm, offset, downbeat, lock, bar }`:
//   bpm       beats per minute of the track at its own tempo (rate 1)
//   offset    time of the first beat in seconds, 0 <= offset < one beat
//   downbeat  which of the first four beats opens a bar (0..3)
//   lock      how steady the tempo is, 0..1 (the share of onset energy that falls on the beat)
//   bar       how clear the downbeat is, 0..1 (0 = could be any of the four)
//
// Pipeline: FeatureExtractor (drums and bass stems -> short-time levels, 344 per second) -> onset envelope of the drums
// -> tempo (autocorrelation for a first guess, then a phase histogram over a fine grid of tempi, seeded by the analysed
// BPM) -> offset (the peak of that histogram) -> downbeat (bass and kick level per beat) -> steadiness check, or no grid.

/** Tuning. Not tuned on real music yet (docs/frontend/dj-mode.md says so). */
export const GRID = {
    framesPerSecond: 344, // hop of about 2.9 ms: the resolution of the offset
    minBpm: 60,
    maxBpm: 200,
    foldMin: 70, // without a seed the tempo is folded into this range by halving or doubling
    foldMax: 180,
    seedWindow: 0.06, // the refined tempo stays within 6 % of the seed's octave
    minLock: 0.12, // below this the onsets do not sit on a grid at all
    minSegmentLock: 0.08,
    segmentSeconds: 8,
    segmentAgree: 0.6, // the global beat spot must hold this share of what a segment's own best spot holds
    steadyShare: 0.75, // share of the audible segments that must agree
};

const BEATS_PER_BAR = 4;

const mod = (x, m) => ((x % m) + m) % m;

// --- features ------------------------------------------------------------------------------------------------------

/**
 * Turns the drums and bass stems into three short-time level curves, chunk by chunk (a worker is fed 1M samples at a
 * time instead of copying two whole stems):
 *   full  RMS of the drums            hf  RMS of the drums' first difference (hi-hats, snare, clicks)
 *   low   RMS of drums + bass, low-passed at 150 Hz (kick and bass notes)
 */
export class FeatureExtractor {
    constructor(sampleRate, framesPerSecond = GRID.framesPerSecond) {
        this.sampleRate = sampleRate;
        this.hop = Math.max(16, Math.round(sampleRate / framesPerSecond));
        this.fps = sampleRate / this.hop;
        this.alpha = 1 - Math.exp((-2 * Math.PI * 150) / sampleRate);
        this.full = [];
        this.hf = [];
        this.low = [];
        this.prev = 0;
        this.lp = 0;
        this.sumFull = 0;
        this.sumHf = 0;
        this.sumLow = 0;
        this.count = 0;
    }

    /** @param {Float32Array[]} drums channel chunks of one length  @param {Float32Array[]|null} bass the same span of the bass stem */
    push(drums, bass = null) {
        const n = drums[0].length;
        const d0 = drums[0];
        const d1 = drums[1] ?? null;
        const b0 = bass?.[0] ?? null;
        const b1 = bass?.[1] ?? null;
        for (let i = 0; i < n; i++) {
            const d = d1 ? (d0[i] + d1[i]) * 0.5 : d0[i];
            const b = b0 ? (b1 ? ((b0[i] ?? 0) + (b1[i] ?? 0)) * 0.5 : (b0[i] ?? 0)) : 0; // a stem may be a few samples shorter
            const diff = d - this.prev;
            this.prev = d;
            this.lp += this.alpha * (d + b - this.lp);
            this.sumFull += d * d;
            this.sumHf += diff * diff;
            this.sumLow += this.lp * this.lp;
            if (++this.count === this.hop) {
                this.#flush();
            }
        }
    }

    #flush() {
        this.full.push(Math.sqrt(this.sumFull / this.count));
        this.hf.push(Math.sqrt(this.sumHf / this.count));
        this.low.push(Math.sqrt(this.sumLow / this.count));
        this.sumFull = this.sumHf = this.sumLow = 0;
        this.count = 0;
    }

    finish() {
        return { full: Float32Array.from(this.full), hf: Float32Array.from(this.hf), low: Float32Array.from(this.low), fps: this.fps };
    }
}

const mean = (a) => {
    let s = 0;
    for (let i = 0; i < a.length; i++) s += a[i];

    return a.length ? s / a.length : 0;
};

/**
 * Positive change of a level curve after log compression, so the first frame of an attack from silence counts most and
 * the wobble of a decaying low note (a 60 Hz period is longer than a frame) hardly counts.
 */
function risingEdge(level) {
    const out = new Float32Array(level.length);
    const scale = mean(level) || 1;
    let prev = 0;
    for (let i = 0; i < level.length; i++) {
        const c = Math.log1p((20 * level[i]) / scale);
        out[i] = i > 0 && c > prev ? c - prev : 0;
        prev = c;
    }

    return out;
}

/** The onset envelope of the drums: rising edges of the drums' level, of its high-passed level (half weight: hats may sit between the beats) and of the kick and bass band. */
export function onsetEnvelope({ full, hf, low }) {
    const a = risingEdge(full);
    const b = risingEdge(hf);
    const c = risingEdge(low);
    for (let i = 0; i < a.length; i++) a[i] += 0.5 * b[i] + c[i];

    return a;
}

// --- tempo ---------------------------------------------------------------------------------------------------------

/** Autocorrelation of the (mean-removed) envelope at lags `from..to` frames. */
function autocorrelate(env, from, to) {
    const n = env.length;
    const m = mean(env);
    const x = new Float32Array(n);
    for (let i = 0; i < n; i++) x[i] = env[i] - m;
    const out = new Float64Array(to + 2);
    for (let lag = from - 1; lag <= to + 1; lag++) {
        let s = 0;
        const end = n - lag;
        for (let i = 0; i < end; i++) s += x[i] * x[i + lag];
        out[lag] = s / Math.max(1, end);
    }

    return out;
}

/** First guess of the tempo from the envelope's autocorrelation (the lag of the strongest peak in 60 to 200 BPM), or null. */
export function coarseTempo(env, fps) {
    const from = Math.floor((fps * 60) / GRID.maxBpm);
    const to = Math.ceil((fps * 60) / GRID.minBpm);
    if (env.length < to * 4) {
        return null;
    }
    const acf = autocorrelate(env, from, to);
    let best = from;
    for (let lag = from; lag <= to; lag++) {
        if (acf[lag] > acf[best]) best = lag;
    }
    if (!(acf[best] > 0)) {
        return null;
    }
    // Parabolic interpolation around the peak.
    const a = acf[best - 1];
    const b = acf[best];
    const c = acf[best + 1];
    const shift = a - 2 * b + c !== 0 ? (0.5 * (a - c)) / (a - 2 * b + c) : 0;

    return (fps * 60) / (best + clamp01(shift, -0.5, 0.5));
}

const clamp01 = (x, lo, hi) => Math.min(hi, Math.max(lo, x));

/** `bpm` moved by whole octaves (x2, /2) to the value closest to `target`. */
export function nearestOctave(bpm, target) {
    let best = bpm;
    for (const f of [0.25, 0.5, 1, 2, 4]) {
        if (Math.abs(Math.log(bpm * f / target)) < Math.abs(Math.log(best / target))) best = bpm * f;
    }

    return best;
}

/** `bpm` doubled or halved until it lies in [lo, hi). */
export function foldTempo(bpm, lo = GRID.foldMin, hi = GRID.foldMax) {
    let x = bpm;
    while (x < lo) x *= 2;
    while (x >= hi) x /= 2;

    return x;
}

/** The onsets worth looking at: index and strength of every frame above half the mean positive onset. */
function sparse(env) {
    let sum = 0;
    let n = 0;
    for (let i = 0; i < env.length; i++) {
        if (env[i] > 0) {
            sum += env[i];
            n++;
        }
    }
    const floor = n ? (sum / n) * 0.5 : 0;
    const idx = [];
    const val = [];
    for (let i = 0; i < env.length; i++) {
        if (env[i] > floor) {
            idx.push(i);
            val.push(env[i]);
        }
    }

    return { idx: Int32Array.from(idx), val: Float64Array.from(val) };
}

/**
 * Folds the onsets over a period of `bpm`: how much of their energy falls on the best spot of the beat (three
 * neighbouring bins), and where that spot is. A tempo that is slightly wrong smears the spot; half and double tempo
 * split it in two, so the right tempo scores highest.
 */
export function phaseHistogram(onsets, fps, bpm, from = 0, to = Infinity) {
    const period = (fps * 60) / bpm;
    const bins = Math.max(8, Math.round(period));
    const scale = bins / period;
    const hist = new Float64Array(bins);
    let total = 0;
    const { idx, val } = onsets;
    for (let k = 0; k < idx.length; k++) {
        const i = idx[k];
        if (i < from || i >= to) continue;
        let bin = Math.floor((i - period * Math.floor(i / period)) * scale);
        if (bin >= bins) bin = bins - 1;
        hist[bin] += val[k];
        total += val[k];
    }
    let best = 0;
    let bestSum = -1;
    for (let b = 0; b < bins; b++) {
        const s = hist[(b + bins - 1) % bins] + hist[b] + hist[(b + 1) % bins];
        if (s > bestSum) {
            bestSum = s;
            best = b;
        }
    }

    return { score: total > 0 ? bestSum / total : 0, bin: best, bins, hist, total, period };
}

/** Searches bpm in [lo, hi] at `step` for the best phase histogram score; ties keep the candidate closest to `prefer`. */
function bestTempo(onsets, fps, lo, hi, step, prefer) {
    let best = null;
    for (let bpm = lo; bpm <= hi + 1e-9; bpm += step) {
        const h = phaseHistogram(onsets, fps, bpm);
        const better = !best || h.score > best.score + 1e-9
            || (Math.abs(h.score - best.score) <= 1e-9 && Math.abs(bpm - prefer) < Math.abs(best.bpm - prefer));
        if (better) best = { ...h, bpm };
    }

    return best;
}

/** Start of the first beat in seconds from the best bin of a histogram (the centroid of its three bins). */
function offsetOf({ hist, bin, bins, period }, fps) {
    const l = hist[(bin + bins - 1) % bins];
    const c = hist[bin];
    const r = hist[(bin + 1) % bins];
    const sum = l + c + r;
    const centre = bin + (sum > 0 ? (r - l) / sum : 0);
    const periodSeconds = period / fps;

    return mod(((centre + 0.5) / bins) * periodSeconds, periodSeconds);
}

// --- downbeat ------------------------------------------------------------------------------------------------------

/** Which of the four beats opens the bar: the one whose beats carry the most kick and bass level on average. */
function findDownbeat(low, fps, bpm, offset) {
    const periodFrames = (fps * 60) / bpm;
    const sums = new Float64Array(BEATS_PER_BAR);
    const counts = new Float64Array(BEATS_PER_BAR);
    const window = Math.max(1, Math.floor(periodFrames * 0.25));
    for (let k = 0; ; k++) {
        const frame = Math.round((offset * fps) + k * periodFrames);
        if (frame + window >= low.length) break;
        let s = 0;
        for (let i = 0; i < window; i++) s += low[frame + i];
        sums[k % BEATS_PER_BAR] += s / window;
        counts[k % BEATS_PER_BAR]++;
    }
    const avg = Array.from(sums, (s, i) => (counts[i] ? s / counts[i] : 0));
    const top = Math.max(...avg);
    let best = 0;
    for (let i = 0; i < avg.length; i++) {
        if (avg[i] > avg[best] * 1.0000001) best = i;
    }
    const others = avg.filter((_, i) => i !== best);
    const rest = mean(others);

    return { downbeat: best, bar: top > 0 ? Math.max(0, (top - rest) / top) : 0 };
}

// --- the whole analysis --------------------------------------------------------------------------------------------

/** Do the onsets keep the same spot on the beat all the way through the track? */
function isSteady(onsets, fps, fit, frames) {
    const per = Math.round(GRID.segmentSeconds * fps);
    const segments = Math.max(1, Math.min(6, Math.floor(frames / per)));
    const length = frames / segments;
    const mine = [];
    for (let s = 0; s < segments; s++) {
        const h = phaseHistogram(onsets, fps, fit.bpm, Math.floor(s * length), Math.floor((s + 1) * length));
        mine.push(h);
    }
    const typical = mean(mine.map((h) => h.total));
    const audible = mine.filter((h) => h.total >= typical * 0.15);
    if (!audible.length) {
        return false;
    }
    // A segment agrees when the global spot still holds most of what that segment's own best spot holds (so a pattern
    // with two equal peaks per beat, like a 140 BPM kick under a 70 BPM grid, does not fail when the peaks swap).
    const good = audible.filter((h) => {
        const at = (h.hist[(fit.bin + h.bins - 1) % h.bins] + h.hist[fit.bin] + h.hist[(fit.bin + 1) % h.bins]) / h.total;

        return h.score >= GRID.minSegmentLock && at >= GRID.segmentAgree * h.score;
    });

    return good.length / audible.length >= GRID.steadyShare;
}

/**
 * The grid of a track from its features, or `{ grid: null, reason }` when there is no steady tempo.
 * `seedBpm` is the analysed BPM: it decides the octave (a 70 BPM kick pattern with a 140 BPM seed gets a 140 BPM grid,
 * so grid and analysis agree and Sync keeps its half/double logic) and the range the refinement may move in.
 */
export function analyseFeatures(features, seedBpm = null) {
    const { low, fps } = features;
    const env = onsetEnvelope(features);
    const frames = env.length;
    if (frames < fps * 12) {
        return { grid: null, reason: 'too_short' };
    }
    const onsets = sparse(env);
    const seed = seedBpm > 0 ? seedBpm : null;

    let guess = coarseTempo(env, fps);
    if (!guess) {
        return { grid: null, reason: 'no_tempo' };
    }
    guess = seed ? nearestOctave(guess, seed) : foldTempo(guess);

    const search = (centre) => {
        const lo = centre * (1 - GRID.seedWindow);
        const hi = centre * (1 + GRID.seedWindow);
        const coarse = bestTempo(onsets, fps, lo, hi, centre * 0.0015, centre);

        return bestTempo(onsets, fps, coarse.bpm - centre * 0.003, coarse.bpm + centre * 0.003, 0.004 * (centre / 120), coarse.bpm);
    };
    let fit = search(guess);
    // The autocorrelation and the seed disagree by more than the window: look around the seed as well and keep the better.
    if (seed && Math.abs(guess - seed) / seed > GRID.seedWindow) {
        const other = search(seed);
        if (other.score > fit.score) fit = other;
    }

    if (fit.score < GRID.minLock || !isSteady(onsets, fps, fit, frames)) {
        return { grid: null, reason: 'unsteady', lock: fit.score };
    }
    const offset = offsetOf(fit, fps);
    const { downbeat, bar } = findDownbeat(low, fps, fit.bpm, offset);

    return { grid: { bpm: fit.bpm, offset, downbeat, lock: fit.score, bar }, reason: null };
}

/** Convenience for tests and the no-worker fallback: stems as arrays of channels -> grid. */
export function analyseSignals({ drums, bass = null, sampleRate, seedBpm = null }) {
    const extractor = new FeatureExtractor(sampleRate);
    const step = 1 << 20;
    for (let at = 0; at < drums[0].length; at += step) {
        extractor.push(drums.map((c) => c.subarray(at, at + step)), bass ? bass.map((c) => c.subarray(at, at + step)) : null);
    }

    return analyseFeatures(extractor.finish(), seedBpm);
}

// --- using a grid --------------------------------------------------------------------------------------------------

export const beatPeriod = (grid) => 60 / grid.bpm;
/** Time of beat number `k` (k = 0 is the first beat of the grid). */
export const beatAt = (grid, k) => grid.offset + (k * 60) / grid.bpm;
/** Fractional beat number of time `t`. */
export const beatIndex = (grid, t) => ((t - grid.offset) * grid.bpm) / 60;
/** Does beat `k` open a bar? */
export const isDownbeat = (grid, k) => mod(k - grid.downbeat, BEATS_PER_BAR) === 0;

/** Time of the beat nearest to `t` (`nearest`), the first at or after it (`next`) or the last at or before it (`prev`); never before 0. */
export function snapToBeat(grid, t, mode = 'nearest') {
    const index = beatIndex(grid, t);
    const k = mode === 'next' ? Math.ceil(index - 1e-6) : mode === 'prev' ? Math.floor(index + 1e-6) : Math.round(index);

    return Math.max(0, beatAt(grid, Math.max(0, k)));
}

/** Time of the bar line (a downbeat) nearest to `t`, never before 0. */
export function snapToBar(grid, t) {
    const k = Math.round((beatIndex(grid, t) - grid.downbeat) / BEATS_PER_BAR) * BEATS_PER_BAR + grid.downbeat;

    return Math.max(0, beatAt(grid, Math.max(0, k)));
}

/** Fraction of a beat that `t` is past the last beat, 0 <= x < 1. */
export const beatPhase = (grid, t) => mod(beatIndex(grid, t), 1);

/** The grid with its offset moved by `seconds`, renumbered so the offset stays inside the first beat. */
export function shiftGrid(grid, seconds) {
    const period = beatPeriod(grid);
    let offset = grid.offset + seconds;
    let downbeat = grid.downbeat;
    while (offset < 0) {
        offset += period;
        downbeat = mod(downbeat - 1, BEATS_PER_BAR);
    }
    while (offset >= period) {
        offset -= period;
        downbeat = mod(downbeat + 1, BEATS_PER_BAR);
    }

    return { ...grid, offset, downbeat };
}

/** The grid with the beat nearest to `t` as the start of a bar. */
export function setDownbeatAt(grid, t) {
    return { ...grid, downbeat: mod(Math.round(beatIndex(grid, t)), BEATS_PER_BAR) };
}

/** Beats between `from` and `to` seconds: `{ time, k, bar }` (bar: it opens a bar). */
export function beatList(grid, from, to) {
    const first = Math.max(0, Math.ceil(beatIndex(grid, from) - 1e-9));
    const out = [];
    for (let k = first; beatAt(grid, k) <= to; k++) {
        out.push({ time: beatAt(grid, k), k, bar: isDownbeat(grid, k) });
    }

    return out;
}

/** SVG path data of the beat ticks over a waveform of `duration` seconds drawn `width` units wide: `{ beat, bar }`. */
export function ticksPath(grid, duration, width = 800, height = 100) {
    if (!grid || !(duration > 0)) {
        return { beat: '', bar: '' };
    }
    const beat = [];
    const bar = [];
    for (const { time, bar: isBar } of beatList(grid, 0, duration)) {
        const x = ((time / duration) * width).toFixed(1);
        (isBar ? bar : beat).push(`M${x} 0V${height}`);
    }

    return { beat: beat.join(''), bar: bar.join('') };
}

/** Beat period ratio of the decks in wall-clock time, snapped to 1/2, 1 or 2 when close (Sync matches in half or double time). */
function periodRatio(mine, other) {
    const ratio = mine / other;
    for (const snap of [0.5, 1, 2]) {
        if (Math.abs(ratio / snap - 1) < 0.1) return snap;
    }

    return ratio;
}

/**
 * How far to move deck S (seconds of its track) so its beats sit on deck O's beats, at one instant: `posS` and `posO`
 * are the track positions of the decks then, `rateS` and `rateO` their playback rates. The shorter way round, at most
 * half a beat.
 */
export function phaseShift({ gridS, posS, rateS, gridO, posO, rateO }) {
    const n = periodRatio(beatPeriod(gridS) / rateS, beatPeriod(gridO) / rateO);
    const target = mod(beatIndex(gridO, posO) / n, 1);
    const mine = mod(beatIndex(gridS, posS), 1);
    let d = target - mine;
    if (d > 0.5) d -= 1;
    if (d <= -0.5) d += 1;

    return d * beatPeriod(gridS);
}

// --- storage -------------------------------------------------------------------------------------------------------

/**
 * Key of a track's stems for the grid and cue caches. The API has no file hash, so this is the track, its length and
 * the size of its stem files: separating the track again (another model, another encoder) changes it.
 */
export function stemsFingerprint(trackId, answer) {
    const bytes = Object.values(answer?.stems ?? {}).reduce((sum, s) => sum + (s.bytes ?? 0), 0);

    return `${trackId}:${Math.round((answer?.duration ?? 0) * 1000)}:${bytes}`;
}

/** What goes into localStorage for a grid, or for a track that has none. */
export const serializeGrid = (grid) => (grid ? { ok: 1, bpm: grid.bpm, offset: grid.offset, downbeat: grid.downbeat, lock: grid.lock, bar: grid.bar } : { ok: 0 });

/** A stored grid back (`undefined` = nothing usable stored, `null` = the track has no grid, else the grid). */
export function parseGrid(raw) {
    if (!raw || typeof raw !== 'object') {
        return undefined;
    }
    if (raw.ok === 0) {
        return null;
    }
    const { bpm, offset, downbeat } = raw;
    if (!(bpm > 20 && bpm < 400) || !Number.isFinite(offset) || offset < 0 || ![0, 1, 2, 3].includes(downbeat)) {
        return undefined;
    }

    return { bpm, offset, downbeat, lock: Number(raw.lock) || 0, bar: Number(raw.bar) || 0 };
}
