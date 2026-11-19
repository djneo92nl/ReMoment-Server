// Stem transitions (docs/frontend/dj-mode.md, phase D4): what the faders and the crossfader do over N bars, as keyframes
// in beats, free of Web Audio so `node --test` can check them. The engine plays them as gain curves on the audio clock.
//
// A plan is `{ beats, inStartBeat, out: { stem: keyframes }, in: { stem: keyframes }, cross: keyframes }`; keyframes are
// `[{ at, v }]` in beats from the start of the plan, linear in between. `cross` is how far the crossfader is towards the
// incoming deck (0 = only the outgoing deck, 1 = only the incoming one); the caller maps it to deck A/B.

import { crossfade } from './math.js';

export const BARS = [8, 16, 32];
export const BEATS_PER_BAR = 4;
const SNAP = 0.05; // a "step" is a ramp of this many beats (about 25 ms at 120 BPM): no click, still a cut

export const PRESETS = {
    blend: { label: 'Blend', hint: 'Crossfade with a bass swap half way' },
    vocals_drums: { label: 'Vocals out, drums in', hint: 'Vocals go first, the new drums arrive, then the rest swaps' },
    drop: { label: 'Drop', hint: 'Only the drums for the last bar, then the new track on the downbeat' },
};

const ramp = (from, to, at, length = 1) => [{ at, v: from }, { at: at + length, v: to }];

/** A step from `from` to `to` at beat `at`. */
const step = (from, to, at) => ramp(from, to, at, SNAP);

/** Joins pieces (each a list of keyframes) into one sorted list that holds its first value before and its last after. */
function join(...pieces) {
    return pieces.flat().sort((a, b) => a.at - b.at);
}

const hold = (v, at) => [{ at, v }];

/**
 * @param {object} o
 * @param {string} o.preset      key of PRESETS
 * @param {number} o.bars        8, 16 or 32 (any whole number of 2 or more works)
 * @param {string[]} o.outStems  stems of the outgoing deck
 * @param {string[]} o.inStems   stems of the incoming deck
 * @param {Record<string, number>} o.outLevels fader positions to start from (the outgoing deck)
 * @param {Record<string, number>} o.inLevels  fader positions the incoming deck ends on (what it is mixed up to)
 * @param {number} o.cross0      how far towards the incoming deck the crossfader starts, 0..1
 */
export function buildPlan({ preset, bars, outStems, inStems, outLevels, inLevels, cross0 = 0 }) {
    const beats = bars * BEATS_PER_BAR;
    const bar = BEATS_PER_BAR;
    const half = Math.round(bars / 2) * bar; // the bar line of the swap
    const out = {};
    const inn = {};
    let cross;
    let inStartBeat = 0;
    const lvl = (levels, stem) => levels[stem] ?? 1;

    if (preset === 'blend') {
        // Everything crosses over the whole length; the bass swaps in one beat on a bar line, so only one bass sounds.
        for (const s of outStems) out[s] = s === 'bass' ? join(hold(lvl(outLevels, s), 0), step(lvl(outLevels, s), 0, half)) : hold(lvl(outLevels, s), 0);
        for (const s of inStems) inn[s] = s === 'bass' ? join(hold(0, 0), step(0, lvl(inLevels, s), half)) : hold(lvl(inLevels, s), 0);
        cross = ramp(cross0, 1, 0, beats);
    } else if (preset === 'vocals_drums') {
        const q = Math.round(bars / 4) * bar; // a quarter
        // outgoing: vocals fade over the first quarter, drums and bass leave at the half, the rest goes with the crossfader
        for (const s of outStems) {
            const v = lvl(outLevels, s);
            out[s] = s === 'vocals' ? ramp(v, 0, 0, q) : s === 'drums' || s === 'bass' ? join(hold(v, 0), step(v, 0, half)) : hold(v, 0);
        }
        // incoming: drums first (over the first quarter), bass and the rest at the half, vocals at three quarters
        for (const s of inStems) {
            const v = lvl(inLevels, s);
            if (s === 'drums') inn[s] = ramp(0, v, 0, q);
            else if (s === 'vocals') inn[s] = join(hold(0, 0), step(0, v, half + q));
            else inn[s] = join(hold(0, 0), step(0, v, half));
        }
        cross = join(ramp(cross0, 0.5, 0, bar), ramp(0.5, 1, beats - bar, bar));
    } else if (preset === 'drop') {
        // outgoing plays to the last bar, then only its drums; the new track comes in whole on the downbeat after the plan
        const cut = (bars - 1) * bar;
        for (const s of outStems) out[s] = s === 'drums' ? hold(lvl(outLevels, s), 0) : join(hold(lvl(outLevels, s), 0), step(lvl(outLevels, s), 0, cut));
        for (const s of inStems) inn[s] = hold(lvl(inLevels, s), 0);
        cross = join(hold(cross0, 0), step(cross0, 1, beats - SNAP));
        inStartBeat = beats; // the incoming deck starts on the bar line the plan ends on
    } else {
        throw new Error(`Unknown transition preset ${preset}`);
    }

    return { preset, bars, beats, inStartBeat, out, in: inn, cross };
}

/** Value of keyframes at beat `at`: linear between them, the first value before the first and the last after the last. */
export function valueAt(keys, at) {
    if (!keys.length) return 0;
    if (at <= keys[0].at) return keys[0].v;
    for (let i = 1; i < keys.length; i++) {
        if (at <= keys[i].at) {
            const a = keys[i - 1];
            const b = keys[i];

            return b.at === a.at ? b.v : a.v + ((b.v - a.v) * (at - a.at)) / (b.at - a.at);
        }
    }

    return keys[keys.length - 1].v;
}

/** Everything of a plan at beat `at`: `{ out: {stem: v}, in: {stem: v}, cross }`. */
export function planAt(plan, at) {
    const read = (group) => Object.fromEntries(Object.entries(group).map(([s, keys]) => [s, valueAt(keys, at)]));

    return { out: read(plan.out), in: read(plan.in), cross: valueAt(plan.cross, at) };
}

/** Samples keyframes into `points` values over [0, beats] (a Float32Array for `setValueCurveAtTime`). */
export function sampleCurve(keys, beats, points) {
    const out = new Float32Array(points);
    for (let i = 0; i < points; i++) out[i] = valueAt(keys, (i / (points - 1)) * beats);

    return out;
}

/** Curve points for a plan: 8 per beat (the 0.05 beat steps are still ramps of at least one point), capped. */
export const curvePoints = (beats) => Math.min(2048, Math.max(2, Math.round(beats * 8) + 1));

/** Deck gains of the crossfader for a position `x` towards the incoming deck: `{ out, in }`, equal power. */
export function fadeGains(x) {
    const { a, b } = crossfade(x);

    return { out: a, in: b };
}

/** Seconds of a plan at a tempo: `beats` beats of a deck whose grid is `bpm` playing at `rate`. */
export const planSeconds = (beats, bpm, rate) => (beats * 60) / (bpm * rate);
