"""Turns the per-patch predictions of a network (one row per patch of audio) into what the app stores: a curve with
one point per window of a few seconds (mood over time, workstream 16.3) or one mean vector per time range (the sound of
a section, workstream 16.5). Pure Python on purpose: no numpy, so it can be tested anywhere.

The networks slide a fixed patch over the audio and Essentia does not say where each patch sits, so a row's time is
worked out from the row count: the patch centres run evenly from half a patch after the start to half a patch before the
end of the track. That is exact for the usual hop (the patches tile the audio) and a small shift otherwise; a window of
a few seconds hides it (unverified on real audio, see docs/architecture/mood-curve.md)."""

import math

# Seconds of audio one patch of each network covers (MusiCNN 187 frames and Discogs-EffNet 128 frames of 256 samples at 16 kHz).
PATCH_MUSICNN = 3.0
PATCH_EFFNET = 2.0


def centers(count, duration, patch):
    """The time (seconds) of the centre of each of `count` patches of a track `duration` seconds long."""
    if count <= 0:
        return []
    if count == 1:
        return [duration / 2.0]
    half = min(patch / 2.0, duration / 2.0)
    step = (duration - 2.0 * half) / (count - 1)
    return [half + i * step for i in range(count)]


def _mean(rows, indexes):
    n = len(indexes)
    width = len(rows[0])
    return [sum(float(rows[i][c]) for i in indexes) / n for c in range(width)]


def _nearest(times, target):
    return min(range(len(times)), key=lambda i: abs(times[i] - target))


def window_means(rows, duration, patch, window):
    """One mean row per window of `window` seconds from 0 to `duration`; a window with no patch centre uses the nearest patch."""
    if len(rows) == 0 or duration <= 0 or window <= 0:
        return []
    times = centers(len(rows), duration, patch)
    bins = max(1, int(math.ceil(duration / window)))
    out = []
    for b in range(bins):
        lo, hi = b * window, (b + 1) * window
        inside = [i for i, t in enumerate(times) if lo <= t < hi or (b == bins - 1 and t >= lo)]
        out.append(_mean(rows, inside or [_nearest(times, (lo + min(hi, duration)) / 2.0)]))
    return out


def range_means(rows, duration, patch, ranges):
    """One mean row per (start, end) range of seconds; a range with no patch centre uses the patch nearest its middle."""
    if len(rows) == 0 or duration <= 0:
        return []
    times = centers(len(rows), duration, patch)
    out = []
    for start, end in ranges:
        inside = [i for i, t in enumerate(times) if start <= t < end]
        out.append(_mean(rows, inside or [_nearest(times, (start + end) / 2.0)]))
    return out
