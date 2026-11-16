// The arithmetic of the stem mixer, free of the Web Audio API so `node --test tests/Js` can check it.
// docs/frontend/stem-mixer.md

export const STEMS = ['vocals', 'drums', 'bass', 'other'];

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
    const anySolo = STEMS.some((s) => solo[s]);

    return Object.fromEntries(STEMS.map((s) => {
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

/** Decoded float32 stereo stems take about this much memory: 4 stems x seconds x 44.1 kHz x 2 channels x 4 bytes, in MB. */
export function decodedMegabytes(seconds, sampleRate = 44100) {
    return Math.round((4 * seconds * sampleRate * 2 * 4) / 1e6);
}
