"""Instrumental detection from a vocals loudness curve (dB per frame, 2 frames/s).
Pure Python on purpose: the same maths goes into the service. Parameters: DROP dB under the track's own
90th percentile, MIN_LEN seconds, median smoothing of SMOOTH frames."""
RATE = 2
DROP = 20.0
MIN_LEN = 8.0
SMOOTH = 5


def median_smooth(v, k=SMOOTH):
    h = k // 2
    return [sorted(v[max(0, i - h):i + h + 1])[len(v[max(0, i - h):i + h + 1]) // 2] for i in range(len(v))]


def percentile(v, p):
    s = sorted(v)
    return s[min(len(s) - 1, int(round(p / 100 * (len(s) - 1))))]


def detect(vocals, drop=DROP, min_len=MIN_LEN, smooth=SMOOTH, floor=-50.0):
    """-> (vocal_ratio, instrumental [(start, end)], threshold_db)"""
    n = len(vocals)
    if n == 0:
        return 0.0, [], None
    v = median_smooth(vocals, smooth)
    ref = percentile(v, 90)
    if ref < floor:  # nothing vocal-like anywhere: the whole track is instrumental
        return 0.0, [(0.0, n / RATE)], ref
    thr = ref - drop
    quiet = [x < thr for x in v]
    ranges, i = [], 0
    while i < n:
        if quiet[i]:
            j = i
            while j < n and quiet[j]:
                j += 1
            if (j - i) / RATE >= min_len:
                ranges.append((i / RATE, j / RATE))
            i = j
        else:
            i += 1
    inst = sum(e - s for s, e in ranges)
    return round(1 - inst / (n / RATE), 3), ranges, round(thr, 1)
