// Run: node --test tests/Js
import assert from 'node:assert/strict';
import test from 'node:test';
import { beatLabel, beatLoop, loopFromPoints, sanitizeCues, scaleLoop, snapPosition, wrapLoop } from '../../resources/js/mixer/cues.js';

const grid = { bpm: 120, offset: 0.25, downbeat: 0, lock: 1, bar: 1 }; // a beat every 0.5 s: 0.25, 0.75, 1.25 ...

test('a beat loop starts on the nearest beat and is as long as its beats', () => {
    assert.deepEqual(beatLoop(grid, 3.9, 4, 200), { start: 3.75, end: 5.75, beats: 4 });
    assert.deepEqual(beatLoop(grid, 4.1, 1, 200), { start: 4.25, end: 4.75, beats: 1 });
    const quarter = beatLoop(grid, 3.9, 0.25, 200);
    assert.equal(quarter.start, 3.75);
    assert.ok(Math.abs(quarter.end - 3.875) < 1e-12);
    // without snapping it starts exactly here
    assert.equal(beatLoop(grid, 3.9, 2, 200, false).start, 3.9);
});

test('a beat loop near the end of the track is moved back to fit, and too long a loop is refused', () => {
    const loop = beatLoop(grid, 9.9, 8, 10);
    assert.ok(Math.abs(loop.start - 6) < 1e-12 && Math.abs(loop.end - 10) < 1e-12);
    assert.equal(beatLoop(grid, 1, 8, 3), null);
});

test('loopFromPoints orders the points and refuses a click', () => {
    assert.deepEqual(loopFromPoints(5, 2, 100), { start: 2, end: 5, beats: null });
    assert.equal(loopFromPoints(2, 2.01, 100), null);
    assert.deepEqual(loopFromPoints(-3, 500, 100), { start: 0, end: 100, beats: null });
});

test('halving and doubling a loop keep its start and its beats', () => {
    const loop = { start: 4, end: 6, beats: 4 };
    assert.deepEqual(scaleLoop(loop, 0.5, 100), { start: 4, end: 5, beats: 2 });
    assert.deepEqual(scaleLoop(loop, 2, 100), { start: 4, end: 8, beats: 8 });
    assert.equal(scaleLoop(loop, 2, 7), null); // would run past the end of the track
    assert.equal(scaleLoop({ start: 1, end: 1.03, beats: null }, 0.5, 100), null);
    assert.equal(scaleLoop({ start: 1, end: 3, beats: null }, 2, 100).beats, null);
});

test('a position that runs on past the loop end wraps into the loop, repeatedly', () => {
    const loop = { start: 10, end: 12 };
    assert.equal(wrapLoop(9, loop), 9); // before the loop it plays on
    assert.equal(wrapLoop(11.5, loop), 11.5);
    assert.equal(wrapLoop(12, loop), 10);
    assert.ok(Math.abs(wrapLoop(12.5, loop) - 10.5) < 1e-12);
    assert.ok(Math.abs(wrapLoop(17.25, loop) - 11.25) < 1e-12); // two and a half laps on
    assert.equal(wrapLoop(50, null), 50);
});

test('a loop of N beats at a tempo takes N beat periods of track time, so the wrap lands on a beat', () => {
    const loop = beatLoop(grid, 5, 2, 100);
    // 25 loop laps later the position is the same spot of the loop, and a whole number of beats from the start
    const raw = loop.start + 25 * (loop.end - loop.start) + 0.3;
    assert.ok(Math.abs(wrapLoop(raw, loop) - (loop.start + 0.3)) < 1e-9);
});

test('snapPosition snaps only with a grid and quantize on', () => {
    assert.equal(snapPosition(grid, 3.9, true), 3.75);
    assert.equal(snapPosition(grid, 3.9, false), 3.9);
    assert.equal(snapPosition(null, 3.9, true), 3.9);
});

test('labels of beat loops', () => {
    assert.deepEqual([0.25, 0.5, 1, 2, 8].map(beatLabel), ['1/4', '1/2', '1', '2', '8']);
    assert.equal(beatLabel(null), null);
});

test('stored cues are made safe', () => {
    assert.deepEqual(sanitizeCues({ cue: 12.5, hot: [1, null, 999, 'x'] }, 200), { cue: 12.5, hot: [1, null, null, null] });
    assert.deepEqual(sanitizeCues(null, 200), { cue: null, hot: [null, null, null, null] });
    assert.deepEqual(sanitizeCues({ cue: -1, hot: 'nope' }, 200), { cue: null, hot: [null, null, null, null] });
});
