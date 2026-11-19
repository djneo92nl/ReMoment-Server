// Run: node --test tests/Js
import assert from 'node:assert/strict';
import test from 'node:test';
import {
    analyseSignals, beatAt, beatIndex, beatList, beatPhase, foldTempo, nearestOctave, parseGrid, phaseShift, serializeGrid,
    setDownbeatAt, shiftGrid, snapToBeat, stemsFingerprint, ticksPath,
} from '../../resources/js/mixer/beats.js';

const SR = 22050;

/** Deterministic pseudo random numbers (mulberry32), so the tests do not flake. */
function rng(seed) {
    let a = seed;

    return () => {
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** A kick (decaying 60 Hz sine) at each time in `times`, `gains` loud, into `out`. */
function addKicks(out, times, gains, freq = 60) {
    times.forEach((t, n) => {
        const start = Math.round(t * SR);
        const len = Math.round(0.06 * SR);
        for (let i = 0; i < len && start + i < out.length; i++) {
            out[start + i] += gains[n] * Math.sin((2 * Math.PI * freq * i) / SR) * Math.exp(-i / (0.015 * SR));
        }
    });
}

/** Drums with a click per beat (a hi-hat tick between them), and a bass stem louder on the first beat of each bar. */
function track({ bpm, offset, seconds = 24, barStart = 0, everyBeat = true, seed = 1 }) {
    const period = 60 / bpm;
    const drums = new Float32Array(seconds * SR);
    const bass = new Float32Array(seconds * SR);
    const noise = rng(seed);
    const beats = [];
    for (let t = offset; t < seconds - 0.1; t += period) beats.push(t);
    addKicks(drums, everyBeat ? beats : beats.filter((_, k) => k % 2 === 0), beats.map(() => 0.8));
    // a hi-hat tick (a few noise samples) between the beats
    for (const t of beats) {
        const s = Math.round((t + period / 2) * SR);
        for (let i = 0; i < 40 && s + i < drums.length; i++) drums[s + i] += 0.15 * (noise() - 0.5);
    }
    // the bass note that opens each bar is louder (barStart = which beat of the first four opens a bar)
    addKicks(bass, beats, beats.map((_, k) => ((k - barStart) % 4 + 4) % 4 === 0 ? 0.9 : 0.25), 55);

    return { drums: [drums], bass: [bass], beats, period };
}

const near = (grid, truth, toleranceMs, count = 8) => {
    // every beat of the true grid has a grid beat within the tolerance (checked at the start, middle and end)
    const ks = [0, 1, 2, 3, Math.floor(truth.beats.length / 2), truth.beats.length - 3, truth.beats.length - 2, truth.beats.length - 1].slice(0, count);
    for (const k of ks) {
        const t = truth.beats[k];
        const mine = beatAt(grid, Math.round(beatIndex(grid, t)));
        assert.ok(Math.abs(mine - t) * 1000 <= toleranceMs, `beat ${k}: truth ${t.toFixed(4)} grid ${mine.toFixed(4)} (${((mine - t) * 1000).toFixed(1)} ms)`);
    }
};

test('a click track at a known tempo and offset is recovered within a few ms, to the end of the track', () => {
    const truth = track({ bpm: 124, offset: 0.173 });
    const { grid } = analyseSignals({ ...truth, sampleRate: SR, seedBpm: 124 });
    assert.ok(grid, 'a grid is found');
    assert.ok(Math.abs(grid.bpm - 124) < 0.05, `bpm ${grid.bpm}`);
    near(grid, truth, 5);
    assert.ok(grid.lock > 0.5);
});

test('a slightly wrong analysed BPM is refined to the real one', () => {
    const truth = track({ bpm: 128, offset: 0.31 });
    const { grid } = analyseSignals({ ...truth, sampleRate: SR, seedBpm: 126.8 });
    assert.ok(Math.abs(grid.bpm - 128) < 0.05, `bpm ${grid.bpm}`);
    near(grid, truth, 5);
});

test('without a seed the tempo is found and folded into 70 to 180', () => {
    const truth = track({ bpm: 100, offset: 0.05 });
    const { grid } = analyseSignals({ ...truth, sampleRate: SR });
    assert.ok(grid);
    assert.ok(Math.abs(grid.bpm - 100) < 0.1 || Math.abs(grid.bpm - 200) < 0.2 || Math.abs(grid.bpm - 50) < 0.1, `bpm ${grid.bpm}`);
    near(grid, truth, 5);
});

test('half and double tempo are resolved with the analysed BPM', () => {
    const truth = track({ bpm: 70, offset: 0.2 });
    const same = analyseSignals({ ...truth, sampleRate: SR, seedBpm: 70 }).grid;
    assert.ok(Math.abs(same.bpm - 70) < 0.1, `seed 70 -> ${same.bpm}`);
    const double = analyseSignals({ ...truth, sampleRate: SR, seedBpm: 140 }).grid;
    assert.ok(Math.abs(double.bpm - 140) < 0.2, `seed 140 -> ${double.bpm}`);
    // the double-tempo grid still has a beat on every kick
    near(double, truth, 5);

    const fast = track({ bpm: 140, offset: 0.2 });
    const half = analyseSignals({ ...fast, sampleRate: SR, seedBpm: 70 }).grid;
    assert.ok(Math.abs(half.bpm - 70) < 0.1, `seed 70 on 140 -> ${half.bpm}`);
});

test('nearestOctave and foldTempo', () => {
    assert.equal(nearestOctave(70, 140), 140);
    assert.equal(nearestOctave(140, 70), 70);
    assert.equal(nearestOctave(124, 126), 124);
    assert.equal(foldTempo(62), 124);
    assert.equal(foldTempo(250), 125);
    assert.equal(foldTempo(100), 100);
});

test('the downbeat is the beat with the most kick and bass, whichever of the four it is', () => {
    for (const barStart of [0, 1, 2, 3]) {
        const truth = track({ bpm: 120, offset: 0.1, barStart, seconds: 40 });
        const { grid } = analyseSignals({ ...truth, sampleRate: SR, seedBpm: 120 });
        assert.equal(grid.downbeat, barStart, `bar opens on beat ${barStart}`);
        assert.ok(grid.bar > 0.3);
    }
});

test('noise has no grid', () => {
    const noise = rng(7);
    const drums = new Float32Array(24 * SR).map(() => (noise() - 0.5) * 0.5);
    const result = analyseSignals({ drums: [drums], sampleRate: SR, seedBpm: 120 });
    assert.equal(result.grid, null);
});

test('a tempo that wanders has no grid, and a track that is too short has none either', () => {
    const noise = rng(3);
    const drums = new Float32Array(40 * SR);
    const times = [];
    for (let t = 0.2; t < 39; t += 0.5 * (0.6 + noise() * 0.8)) times.push(t); // beats 30 % off the mean, at random
    addKicks(drums, times, times.map(() => 0.8));
    assert.equal(analyseSignals({ drums: [drums], sampleRate: SR, seedBpm: 120 }).grid, null);

    const short = track({ bpm: 120, offset: 0.1, seconds: 6 });
    assert.equal(analyseSignals({ ...short, sampleRate: SR, seedBpm: 120 }).reason, 'too_short');
});

test('a track whose tempo steps away half way has no grid', () => {
    const drums = new Float32Array(40 * SR);
    const times = [];
    for (let t = 0.2; t < 20; t += 0.5) times.push(t);
    for (let t = 20.13; t < 39; t += 0.46) times.push(t);
    addKicks(drums, times, times.map(() => 0.8));
    const { grid } = analyseSignals({ drums: [drums], sampleRate: SR, seedBpm: 120 });
    assert.equal(grid, null);
});

test('beat helpers: times, phase, snapping, ticks', () => {
    const grid = { bpm: 120, offset: 0.25, downbeat: 1, lock: 1, bar: 1 }; // a beat every 0.5 s
    assert.equal(beatAt(grid, 3), 1.75);
    assert.equal(beatIndex(grid, 1.75), 3);
    assert.equal(beatPhase(grid, 1.0), 0.5);
    assert.equal(snapToBeat(grid, 0.9), 0.75);
    assert.equal(snapToBeat(grid, 0.9, 'next'), 1.25);
    assert.equal(snapToBeat(grid, 0.9, 'prev'), 0.75);
    assert.equal(snapToBeat(grid, 0.75, 'next'), 0.75);
    assert.equal(snapToBeat(grid, 0), 0.25);

    const list = beatList(grid, 0, 3);
    assert.deepEqual(list.map((b) => b.time), [0.25, 0.75, 1.25, 1.75, 2.25, 2.75]);
    assert.deepEqual(list.filter((b) => b.bar).map((b) => b.k), [1, 5]);

    const ticks = ticksPath(grid, 4, 800);
    assert.equal(ticks.bar, 'M150.0 0V100M550.0 0V100');
    assert.equal(ticks.beat.split('M').length - 1, 6);
    assert.deepEqual(ticksPath(null, 4), { beat: '', bar: '' });
});

test('shiftGrid moves the offset and keeps the bars where they were', () => {
    const grid = { bpm: 120, offset: 0.1, downbeat: 0, lock: 1, bar: 1 };
    const later = shiftGrid(grid, 0.005);
    assert.ok(Math.abs(later.offset - 0.105) < 1e-12);
    const wrapped = shiftGrid(grid, -0.15); // earlier than 0: the first beat is now the one before
    assert.ok(Math.abs(wrapped.offset - 0.45) < 1e-12);
    assert.equal(wrapped.downbeat, 3);
    // the same beats, renumbered: old beat 1 moved 0.15 s earlier is the new beat 0
    assert.ok(Math.abs(beatAt(wrapped, 0) - (beatAt(grid, 1) - 0.15)) < 1e-12);
    const forward = shiftGrid(grid, 0.45);
    assert.ok(Math.abs(forward.offset - 0.05) < 1e-12);
    assert.equal(forward.downbeat, 1);
});

test('setDownbeatAt makes the nearest beat open a bar', () => {
    const grid = { bpm: 120, offset: 0.25, downbeat: 0, lock: 1, bar: 1 };
    assert.equal(setDownbeatAt(grid, 1.76).downbeat, 3); // beat 3 at 1.75
    assert.equal(setDownbeatAt(grid, 2.76).downbeat, 1); // beat 5 at 2.75
});

test('phaseShift lines two decks up, also across tempo and rate differences', () => {
    const a = { bpm: 120, offset: 0.1, downbeat: 0 };
    const b = { bpm: 120, offset: 0.37, downbeat: 0 };
    // deck O is at 5.1 s: exactly on a beat (beat 10). Deck S at 7.37 s is on its beat 14: already aligned.
    assert.ok(Math.abs(phaseShift({ gridS: b, posS: 7.37, rateS: 1, gridO: a, posO: 5.1, rateO: 1 })) < 1e-9);
    // S a quarter beat (0.125 s) late: move it back
    assert.ok(Math.abs(phaseShift({ gridS: b, posS: 7.495, rateS: 1, gridO: a, posO: 5.1, rateO: 1 }) + 0.125) < 1e-9);
    // S a quarter beat early: move it forward; the shorter way round is chosen
    assert.ok(Math.abs(phaseShift({ gridS: b, posS: 7.245, rateS: 1, gridO: a, posO: 5.1, rateO: 1 }) - 0.125) < 1e-9);

    // After applying the shift, the phases are equal (random positions, different tempi, synced rate)
    const noise = rng(11);
    for (let n = 0; n < 50; n++) {
        const gridS = { bpm: 100 + noise() * 60, offset: noise() * 0.5, downbeat: 0 };
        const gridO = { bpm: 100 + noise() * 60, offset: noise() * 0.5, downbeat: 0 };
        const rateO = 0.9 + noise() * 0.2;
        const rateS = (gridS.bpm > 0 ? (gridO.bpm * rateO) / gridS.bpm : 1); // synced: equal beat period in wall time
        const posS = noise() * 200;
        const posO = noise() * 200;
        const d = phaseShift({ gridS, posS, rateS, gridO, posO, rateO });
        assert.ok(Math.abs(d) <= 60 / gridS.bpm / 2 + 1e-9);
        assert.ok(Math.abs(beatPhase(gridS, posS + d) - beatPhase(gridO, posO)) < 1e-6 || Math.abs(Math.abs(beatPhase(gridS, posS + d) - beatPhase(gridO, posO)) - 1) < 1e-6);
    }
});

test('phaseShift in half time lines a beat of the slow deck up with a beat of the fast one', () => {
    const slow = { bpm: 70, offset: 0, downbeat: 0 }; // beat every 0.857 s
    const fast = { bpm: 140, offset: 0, downbeat: 0 }; // beat every 0.4286 s
    // fast deck at 0.4286 s (on its beat 1), slow deck at 0.2 s: its beat lands on every second beat of the fast deck
    const d = phaseShift({ gridS: slow, posS: 0.2, rateS: 1, gridO: fast, posO: 60 / 140, rateO: 1 });
    assert.ok(Math.abs(beatPhase(slow, 0.2 + d) - 0.5) < 1e-6 || Math.abs(beatPhase(slow, 0.2 + d)) < 1e-6);
    // the other deck is on an odd beat of the fast grid: the slow one has to be half a beat (one fast beat) off its own
    assert.ok(Math.abs(Math.abs(d) - 0.2) < 0.45); // sanity: a move of at most half a slow beat
});

test('stored grids round-trip and bad data is refused', () => {
    const grid = { bpm: 124.21, offset: 0.173, downbeat: 2, lock: 0.8, bar: 0.4 };
    assert.deepEqual(parseGrid(JSON.parse(JSON.stringify(serializeGrid(grid)))), grid);
    assert.equal(parseGrid(serializeGrid(null)), null); // "no grid" is remembered too
    assert.equal(parseGrid(undefined), undefined);
    assert.equal(parseGrid({ ok: 1, bpm: 0, offset: 0, downbeat: 0 }), undefined);
    assert.equal(parseGrid({ ok: 1, bpm: 120, offset: -1, downbeat: 0 }), undefined);
    assert.equal(parseGrid({ ok: 1, bpm: 120, offset: 0, downbeat: 5 }), undefined);
});

test('the fingerprint changes when the stems are made again', () => {
    const a = { duration: 241.3, stems: { drums: { bytes: 100 }, bass: { bytes: 200 } } };
    const b = { duration: 241.3, stems: { drums: { bytes: 100 }, bass: { bytes: 201 } } };
    assert.equal(stemsFingerprint(42, a), '42:241300:300');
    assert.notEqual(stemsFingerprint(42, a), stemsFingerprint(42, b));
});
