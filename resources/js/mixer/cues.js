// Cues and loops of a deck (docs/frontend/dj-mode.md, phase D3): the arithmetic, free of Web Audio so `node --test`
// can check it. Positions are seconds of the track (not of the wall clock), so a tempo change needs nothing here.

import { beatPeriod, snapToBeat } from './beats.js';

export const HOT_CUES = 4;
/** Beat loop lengths, in beats: a quarter, a half, and 1, 2, 4, 8. */
export const LOOP_BEATS = [0.25, 0.5, 1, 2, 4, 8];
/** The shortest loop that is a loop and not a click (seconds). */
export const MIN_LOOP = 0.02;

const clamp = (x, lo, hi) => Math.min(hi, Math.max(lo, x));

/** A position kept on a beat when the deck has a grid and quantize is on, else as it is. */
export function snapPosition(grid, seconds, quantize = true) {
    return quantize && grid ? snapToBeat(grid, seconds, 'nearest') : seconds;
}

/**
 * A loop of `beats` beats that starts on the beat nearest to `position` (or at `position` itself when `snap` is off), and
 * is moved back so it fits in the track. `{ start, end, beats }`, or null for a track that is shorter than the loop.
 */
export function beatLoop(grid, position, beats, duration, snap = true) {
    const length = beats * beatPeriod(grid);
    if (!(length >= MIN_LOOP) || length > duration) {
        return null;
    }
    let start = snap ? snapToBeat(grid, position, 'nearest') : position;
    start = clamp(start, 0, Math.max(0, duration - length));

    return { start, end: start + length, beats };
}

/** A loop from two marked points (either order), or null when they are too close together. */
export function loopFromPoints(a, b, duration) {
    const start = clamp(Math.min(a, b), 0, duration);
    const end = clamp(Math.max(a, b), 0, duration);

    return end - start >= MIN_LOOP ? { start, end, beats: null } : null;
}

/**
 * The same loop twice as long (`factor` 2) or half as long (`factor` 0.5), keeping its start; null when the result would
 * be shorter than MIN_LOOP or would not fit before the end of the track. A beat loop keeps its length in beats.
 */
export function scaleLoop(loop, factor, duration) {
    const length = (loop.end - loop.start) * factor;
    if (!(length >= MIN_LOOP) || loop.start + length > duration) {
        return null;
    }

    return { start: loop.start, end: loop.start + length, beats: loop.beats ? loop.beats * factor : null };
}

/** Where a position that ran on without looping ends up inside the loop (the position itself while before the loop's end). */
export function wrapLoop(raw, loop) {
    if (!loop || raw < loop.end) {
        return raw;
    }

    return loop.start + ((raw - loop.start) % (loop.end - loop.start));
}

/** A loop that is a whole number of beats long, 1/4 to 8, is shown as such ("1/2", "4"); otherwise null. */
export function beatLabel(beats) {
    if (!beats) {
        return null;
    }

    return beats < 1 ? `1/${Math.round(1 / beats)}` : String(beats);
}

/**
 * What localStorage held for a track's cues, made safe: `{ cue: seconds|null, hot: [seconds|null x4] }`; a position
 * outside the track, or not a number, is dropped.
 */
export function sanitizeCues(raw, duration) {
    const ok = (x) => (typeof x === 'number' && Number.isFinite(x) && x >= 0 && x <= duration ? x : null);

    return {
        cue: ok(raw?.cue),
        hot: Array.from({ length: HOT_CUES }, (_, i) => ok(Array.isArray(raw?.hot) ? raw.hot[i] : null)),
    };
}
