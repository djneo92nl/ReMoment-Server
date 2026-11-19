// The arithmetic of the stem mixer, free of the Web Audio API so `node --test tests/Js` can check it.
// docs/frontend/stem-mixer.md

// Every stem a model can give, in display order: the four of `htdemucs`, plus guitar and piano with `htdemucs_6s`. A deck
// uses the stems its track actually has (the keys of the stems API answer), so a 4-stem and a 6-stem track both load.
export const STEMS = ['vocals', 'drums', 'bass', 'guitar', 'piano', 'other'];

/** The names in `names` that are known stems, in display order. */
export const orderStems = (names) => STEMS.filter((s) => names.includes(s));

/** Tempo range the slider offers (1 = the track's own tempo); SoundTouch sounds cleanest near 1. */
export const RATE_MIN = 0.7;
export const RATE_MAX = 1.4;

export const clamp = (x, lo, hi) => Math.min(hi, Math.max(lo, x));

/**
 * The playback rate that makes a track of `bpm` play at `targetBpm`. A target that is about half or double the
 * tempo is matched in half or double time (the nearest of x, x/2, x*2 to 1), so 70 and 140 mix without a 100 % stretch.
 */
export function syncRate(bpm, targetBpm) {
    if (!(bpm > 0) || !(targetBpm > 0)) {
        return 1;
    }
    let rate = targetBpm / bpm;
    while (rate > Math.SQRT2) rate /= 2;
    while (rate < 1 / Math.SQRT2) rate *= 2;

    return clamp(rate, RATE_MIN, RATE_MAX);
}

/** Equal-power crossfade: position 0 = only deck A, 1 = only deck B. */
export function crossfade(position) {
    const x = clamp(position, 0, 1) * Math.PI / 2;

    return { a: Math.cos(x), b: Math.sin(x) };
}

/**
 * The gain of each stem: its fader, silenced by mute; while any stem is soloed only the soloed (and not muted) ones sound.
 * @param {Record<string, number>} levels 0..1 per stem
 * @param {Record<string, boolean>} muted
 * @param {Record<string, boolean>} solo
 */
export function stemGains(levels, muted, solo) {
    const names = Object.keys(levels);
    const anySolo = names.some((s) => solo[s]);

    return Object.fromEntries(names.map((s) => {
        const audible = anySolo ? solo[s] && !muted[s] : !muted[s];

        return [s, audible ? clamp(levels[s] ?? 1, 0, 1) : 0];
    }));
}

/** `3:07` from seconds. */
export function formatTime(seconds) {
    const s = Math.max(0, Math.floor(seconds || 0));

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/** The Camelot code a track reads as after a pitch shift of `semitones`: a semitone up is seven steps round the wheel. */
export function shiftCamelot(code, semitones) {
    const match = /^(\d{1,2})([AB])$/i.exec(code || '');
    if (!match || !semitones) {
        return code || null;
    }
    const n = ((((Number(match[1]) - 1 + 7 * semitones) % 12) + 12) % 12) + 1;

    return `${n}${match[2].toUpperCase()}`;
}

/** The effective tempo of a deck: its BPM times its playback rate; null without a BPM. */
export function effectiveBpm(bpm, rate) {
    return bpm > 0 ? bpm * rate : null;
}

/** Decoded float32 stereo stems take about this much memory: stems (4, or 6) x seconds x 44.1 kHz x 2 channels x 4 bytes, in MB. */
export function decodedMegabytes(seconds, sampleRate = 44100, stems = 4) {
    return Math.round((stems * seconds * sampleRate * 2 * 4) / 1e6);
}

/**
 * Peak height per bin for a waveform. `channels` are Float32Arrays of one length (the channels of one stem, or of all
 * four stems for the full track). With `sum` the arrays are added sample by sample first (the full track is the sum of
 * its stems); without it each bin takes the largest absolute sample of any array. Result is scaled so the loudest bin is 1.
 */
export function binPeaks(channels, bins, sum = false) {
    const length = channels.reduce((n, c) => Math.max(n, c.length), 0);
    const peaks = new Float32Array(bins);
    if (!length || !bins) {
        return peaks;
    }
    const per = length / bins;
    for (let b = 0; b < bins; b++) {
        const from = Math.floor(b * per);
        const to = Math.max(from + 1, Math.floor((b + 1) * per));
        let peak = 0;
        for (let i = from; i < to; i++) {
            let v = 0;
            if (sum) {
                for (const c of channels) v += c[i] ?? 0;
                v = Math.abs(v);
            } else {
                for (const c of channels) v = Math.max(v, Math.abs(c[i] ?? 0));
            }
            if (v > peak) peak = v;
        }
        peaks[b] = peak;
    }
    const max = peaks.reduce((m, p) => Math.max(m, p), 0);
    if (max > 0) {
        for (let b = 0; b < bins; b++) peaks[b] /= max;
    }

    return peaks;
}

/** A mirrored SVG path (viewBox `0 0 bins height`) for peak heights 0..1; a silent bin keeps a hairline. */
export function peaksPath(peaks, height = 100) {
    const mid = height / 2;
    const up = [];
    const down = [];
    peaks.forEach((p, i) => {
        const h = Math.max(0.5, p * mid).toFixed(1);
        up.push(`${i} ${(mid - h).toFixed(1)}`);
        down.push(`${i} ${(mid + Number(h)).toFixed(1)}`);
    });

    return peaks.length ? `M${up.join('L')}L${down.reverse().join('L')}Z` : '';
}
