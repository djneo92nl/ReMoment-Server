// Run: node --test tests/Js
import assert from 'node:assert/strict';
import test from 'node:test';
import { crossfade, decodedMegabytes, effectiveBpm, formatTime, shiftCamelot, stemGains, syncRate } from '../../resources/js/mixer/math.js';

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
