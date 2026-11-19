// Run: node --test tests/Js
import assert from 'node:assert/strict';
import test from 'node:test';
import { BARS, buildPlan, curvePoints, fadeGains, planAt, planSeconds, sampleCurve, valueAt } from '../../resources/js/mixer/transitions.js';

const stems = ['vocals', 'drums', 'bass', 'other'];
const ones = { vocals: 1, drums: 1, bass: 1, other: 1 };
const make = (preset, bars = 16, extra = {}) => buildPlan({ preset, bars, outStems: stems, inStems: stems, outLevels: ones, inLevels: ones, ...extra });

test('valueAt is linear between keyframes and holds the ends', () => {
    const keys = [{ at: 0, v: 1 }, { at: 4, v: 0 }];
    assert.equal(valueAt(keys, -1), 1);
    assert.equal(valueAt(keys, 2), 0.5);
    assert.equal(valueAt(keys, 9), 0);
});

test('blend: only one bass sounds except in the one-beat swap window on the bar line', () => {
    const plan = make('blend', 16);
    const swap = 8 * 4; // half of 16 bars, a bar line
    let overlap = 0;
    for (let at = 0; at <= plan.beats; at += 0.01) {
        const { out, in: inn } = planAt(plan, at);
        if (out.bass > 1e-9 && inn.bass > 1e-9) {
            overlap++;
            assert.ok(at >= swap - 1e-9 && at <= swap + 0.05 + 1e-9, `both basses at beat ${at}`);
        }
    }
    assert.ok(overlap > 0 && overlap < 10, 'a short overlap, not none and not long');
    assert.equal(planAt(plan, swap - 0.01).out.bass, 1);
    assert.equal(planAt(plan, swap - 0.01).in.bass, 0);
    assert.equal(planAt(plan, swap + 0.5).out.bass, 0);
    assert.equal(planAt(plan, swap + 0.5).in.bass, 1);
    assert.equal(planAt(plan, plan.beats).out.bass, 0);
    assert.equal(planAt(plan, plan.beats).in.bass, 1);
});

test('blend: the crossfader runs from the start to the incoming deck over the whole plan, equal power', () => {
    const plan = make('blend', 8, { cross0: 0 });
    assert.equal(planAt(plan, 0).cross, 0);
    assert.equal(planAt(plan, plan.beats / 2).cross, 0.5);
    assert.equal(planAt(plan, plan.beats).cross, 1);
    const g = fadeGains(0.5);
    assert.ok(Math.abs(g.out ** 2 + g.in ** 2 - 1) < 1e-9);
    assert.equal(planAt(plan, 3).in.vocals, 1); // the other stems of the incoming deck are up from the start
});

test('vocals out, drums in: vocals leave first, new drums come first, bass swaps on the half bar line', () => {
    const plan = make('vocals_drums', 16);
    const q = 4 * 4;
    const half = 8 * 4;
    assert.equal(planAt(plan, q).out.vocals, 0);
    assert.equal(planAt(plan, q).in.drums, 1);
    assert.equal(planAt(plan, q).in.bass, 0); // the new bass is not there yet
    assert.equal(planAt(plan, half - 0.01).out.bass, 1);
    assert.equal(planAt(plan, half + 0.5).out.bass, 0);
    assert.equal(planAt(plan, half + 0.5).in.bass, 1);
    assert.equal(planAt(plan, half + 0.5).in.vocals, 0);
    assert.equal(planAt(plan, half + q + 0.5).in.vocals, 1);
    assert.equal(planAt(plan, 4).cross, 0.5); // centred after the first bar
    assert.equal(planAt(plan, plan.beats).cross, 1);
});

test('drop: the outgoing deck keeps only its drums for the last bar, the new deck starts on the bar line after', () => {
    const plan = make('drop', 8);
    const cut = 7 * 4;
    assert.equal(planAt(plan, cut - 0.01).out.bass, 1);
    assert.equal(planAt(plan, cut + 0.5).out.bass, 0);
    assert.equal(planAt(plan, cut + 0.5).out.vocals, 0);
    assert.equal(planAt(plan, cut + 0.5).out.drums, 1);
    assert.equal(planAt(plan, cut + 0.5).cross, 0);
    assert.equal(planAt(plan, plan.beats).cross, 1);
    assert.equal(plan.inStartBeat, plan.beats);
    assert.equal(make('blend').inStartBeat, 0);
});

test('levels the DJ had are kept: a stem at 70 % ends at 70 %', () => {
    const plan = buildPlan({ preset: 'blend', bars: 8, outStems: stems, inStems: stems, outLevels: ones, inLevels: { ...ones, bass: 0.7, other: 0.5 }, cross0: 0 });
    assert.equal(planAt(plan, plan.beats).in.bass, 0.7);
    assert.equal(planAt(plan, 0).in.other, 0.5);
});

test('six stems and missing stems are handled', () => {
    const six = ['vocals', 'drums', 'bass', 'guitar', 'piano', 'other'];
    const plan = buildPlan({ preset: 'vocals_drums', bars: 8, outStems: six, inStems: ['drums', 'other'], outLevels: {}, inLevels: {}, cross0: 0 });
    assert.deepEqual(Object.keys(plan.out), six);
    assert.deepEqual(Object.keys(plan.in), ['drums', 'other']);
    assert.throws(() => buildPlan({ preset: 'nope', bars: 8, outStems: [], inStems: [], outLevels: {}, inLevels: {} }));
});

test('curves: sampled at 8 points a beat, endpoints exact', () => {
    const plan = make('blend', 8);
    const n = curvePoints(plan.beats);
    assert.equal(n, 8 * 32 + 1);
    const c = sampleCurve(plan.cross, plan.beats, n);
    assert.equal(c[0], 0);
    assert.equal(c[n - 1], 1);
    assert.ok(curvePoints(1000) <= 2048);
});

test('duration of a plan in seconds follows the tempo', () => {
    assert.equal(planSeconds(32, 120, 1), 16);
    assert.ok(Math.abs(planSeconds(32, 126, 120 / 126) - 16) < 1e-9);
    assert.deepEqual(BARS, [8, 16, 32]);
});
