// Run: node --test tests/Js
import assert from 'node:assert/strict';
import test from 'node:test';
import { binPeaks, crossfade, decodedMegabytes, effectiveBpm, formatTime, orderStems, peaksPath, shiftCamelot, stemGains, syncRate } from '../../resources/js/mixer/math.js';

const all = (v) => ({ vocals: v, drums: v, bass: v, other: v });
const off = () => ({ vocals: false, drums: false, bass: false, other: false });

test('syncRate matches a tempo directly, in half time and in double time', () => {
    assert.equal(syncRate(120, 126).toFixed(3), '1.050');
    assert.equal(syncRate(70, 140), 1); // 140 is double 70: same feel, no stretch
    assert.equal(syncRate(140, 70), 1);
    assert.equal(syncRate(75, 140).toFixed(3), '0.933'); // 75 against 140: play 75 at 70
    assert.equal(syncRate(0, 120), 1);
    assert.equal(syncRate(120, null), 1);
});

test('syncRate stays inside the slider range', () => {
    for (const [a, b] of [[60, 99], [99, 60], [100, 141], [141, 100]]) {
        const r = syncRate(a, b);
        assert.ok(r >= 0.7 && r <= 1.4, `${a} -> ${b}: ${r}`);
    }
});

test('crossfade is equal power and silent at the ends', () => {
    assert.deepEqual(crossfade(0), { a: 1, b: 0 });
    const end = crossfade(1);
    assert.ok(end.a < 1e-9 && Math.abs(end.b - 1) < 1e-9);
    const mid = crossfade(0.5);
    assert.ok(Math.abs(mid.a ** 2 + mid.b ** 2 - 1) < 1e-9);
    assert.deepEqual(crossfade(-3), crossfade(0));
    assert.deepEqual(crossfade(9), crossfade(1));
});

test('stem gains follow the faders and mute', () => {
    const muted = { ...off(), drums: true };
    assert.deepEqual(stemGains({ vocals: 1, drums: 1, bass: 0.5, other: 0 }, muted, off()), { vocals: 1, drums: 0, bass: 0.5, other: 0 });
});

test('solo silences every other stem, and a muted solo stays silent', () => {
    assert.deepEqual(stemGains(all(1), off(), { ...off(), bass: true }), { vocals: 0, drums: 0, bass: 1, other: 0 });
    assert.deepEqual(stemGains(all(1), { ...off(), bass: true }, { ...off(), bass: true, vocals: true }), { vocals: 1, drums: 0, bass: 0, other: 0 });
});

test('time, effective tempo and memory', () => {
    assert.equal(formatTime(187.9), '3:07');
    assert.equal(formatTime(-4), '0:00');
    assert.equal(effectiveBpm(120, 1.05), 126);
    assert.equal(effectiveBpm(null, 1), null);
    assert.equal(decodedMegabytes(240), 339); // four stems of a 4 minute track
});

test('a pitch shift moves the Camelot code seven steps per semitone', () => {
    assert.equal(shiftCamelot('8A', 0), '8A');
    assert.equal(shiftCamelot('8A', 1), '3A');
    assert.equal(shiftCamelot('8A', -1), '1A');
    assert.equal(shiftCamelot('12B', 1), '7B');
    assert.equal(shiftCamelot(null, 2), null);
});

test('binPeaks takes the loudest sample per bin and scales the loudest bin to 1', () => {
    const peaks = binPeaks([Float32Array.from([0.1, -0.2, 0.4, 0.1]), Float32Array.from([0, 0, -0.8, 0])], 2);
    assert.equal(peaks.length, 2);
    assert.ok(Math.abs(peaks[0] - 0.25) < 1e-6);
    assert.equal(peaks[1], 1);
});

test('binPeaks adds the arrays first when summing (opposite samples cancel)', () => {
    const peaks = binPeaks([Float32Array.from([0.5, 0.5]), Float32Array.from([-0.5, 0.25])], 2, true);
    assert.equal(peaks[0], 0);
    assert.equal(peaks[1], 1);
});

test('binPeaks of silence or nothing is all zeros, and peaksPath is mirrored around the middle', () => {
    assert.deepEqual([...binPeaks([new Float32Array(10)], 3)], [0, 0, 0]);
    assert.equal(peaksPath([]), '');
    assert.match(peaksPath([1, 0], 100), /^M0 0\.0L1 49\.5L1 50\.5L0 100\.0Z$/);
});

test('a six-stem track mutes and solos like a four-stem one, and the stem list follows the track', () => {
    const six = { vocals: 1, drums: 1, bass: 1, guitar: 0.5, piano: 1, other: 1 };
    const none = Object.fromEntries(Object.keys(six).map((k) => [k, false]));
    assert.deepEqual(Object.keys(stemGains(six, none, none)), Object.keys(six));
    assert.deepEqual(stemGains(six, { ...none, other: true }, { ...none, guitar: true, other: true }), { vocals: 0, drums: 0, bass: 0, guitar: 0.5, piano: 0, other: 0 });
    assert.deepEqual(orderStems(['other', 'piano', 'vocals', 'drums', 'bass', 'guitar', 'strings']), ['vocals', 'drums', 'bass', 'guitar', 'piano', 'other']);
    assert.deepEqual(orderStems(['other', 'bass', 'drums', 'vocals']), ['vocals', 'drums', 'bass', 'other']);
    assert.ok(Math.abs(decodedMegabytes(240, 44100, 6) - decodedMegabytes(240, 44100, 4) * 1.5) <= 1); // six stems cost half as much again
});
