"""Sections of one track: beat-synchronous MFCC + chroma, a recurrence matrix, a checkerboard-kernel novelty curve and
peak picking with a minimum section length; then repetition letters by clustering the segments. This is the librosa
novelty method of the 16.2 spike (docs/architecture/track-sections-spike.md, variants A and A2) the owner judged by ear.
No model, no download."""

import os

import librosa
import numpy as np
import scipy.ndimage
import scipy.signal
import soundfile

VERSION = 1
METHOD = "novelty-v1"
MIN_DURATION = 30.0
HOP = 512


def _f(name, default):
    return float(os.environ.get(name, default))


def settings():
    """Everything tunable by env (read per call so tests and operators can change it). Defaults: A from `long_from`
    seconds up, the coarser A2 below it."""
    return {
        "sr": int(_f("STRUCTURE_SR", 22050)),
        "chunk": _f("STRUCTURE_CHUNK_SECONDS", 0),  # features in chunks of this many seconds (0 = whole track, the default: chunks save ~25% RAM but move some boundaries)
        "chunk_pad": _f("STRUCTURE_CHUNK_PAD_SECONDS", 4),
        "chroma": os.environ.get("STRUCTURE_CHROMA", "cqt"),  # cqt | stft
        "long_from": _f("STRUCTURE_LONG_FROM", 300),
        "min_section_long": _f("STRUCTURE_MIN_SECTION_LONG", 12),
        "prominence_long": _f("STRUCTURE_PROMINENCE_LONG", 0.2),
        "min_section_short": _f("STRUCTURE_MIN_SECTION_SHORT", 16),
        "prominence_short": _f("STRUCTURE_PROMINENCE_SHORT", 0.35),
        "kernel_beats": int(_f("STRUCTURE_KERNEL_BEATS", 16)),
        "noise_flatness": _f("STRUCTURE_NOISE_FLATNESS", 0.3),
        "label_threshold": _f("STRUCTURE_LABEL_THRESHOLD", 0.6),
    }


def duration_of(path):
    """Length in seconds without decoding the audio."""
    return float(soundfile.info(path).duration)


def _z(x):
    return (x - x.mean(1, keepdims=True)) / (x.std(1, keepdims=True) + 1e-8)


def _noise_like(y, sr, limit):
    """Steady broadband noise (static, hiss, a noise-only file): mean spectral flatness near 0.5, music stays below 0.06
    on the spike tracks. Looks at up to two minutes from the middle."""
    n = int(120 * sr)
    mid = max(0, (len(y) - n) // 2)
    return float(np.mean(librosa.feature.spectral_flatness(y=y[mid : mid + n]))) > limit


def _frame_features(y, sr, cfg):
    mf = librosa.feature.mfcc(y=y, sr=sr, n_mfcc=20, hop_length=HOP)
    if cfg["chroma"] == "stft":
        ch = librosa.feature.chroma_stft(y=y, sr=sr, hop_length=HOP, n_fft=2048)
    else:
        ch = librosa.feature.chroma_cqt(y=y, sr=sr, hop_length=HOP)
    return mf, ch


def _features(y, sr, cfg):
    """MFCC and chroma per frame. With a chunk size the track is processed in pieces that overlap by `chunk_pad`
    seconds on both sides (the overlap is discarded), so the CQT never holds the whole track."""
    if not cfg["chunk"] or len(y) <= (cfg["chunk"] + 2 * cfg["chunk_pad"]) * sr:
        return _frame_features(y, sr, cfg)
    total = 1 + len(y) // HOP
    step = max(1, int(cfg["chunk"] * sr / HOP))
    pad = int(cfg["chunk_pad"] * sr / HOP) * HOP
    mfs, chs = [], []
    for f0 in range(0, total, step):
        f1 = min(total, f0 + step)
        a = max(0, f0 * HOP - pad)
        b = len(y) if f1 == total else min(len(y), f1 * HOP + pad)
        mf, ch = _frame_features(y[a:b], sr, cfg)
        lo = (f0 * HOP - a) // HOP
        mfs.append(mf[:, lo : lo + (f1 - f0)])
        chs.append(ch[:, lo : lo + (f1 - f0)])
    return np.hstack(mfs), np.hstack(chs)


def _result(duration, tempo, sections, reason=None):
    out = {"version": VERSION, "duration": round(duration, 2), "tempo": tempo, "method": METHOD, "sections": sections}
    if reason:
        out["reason"] = reason
    return out


def analyze(path, min_section=None, prominence=None):
    cfg = settings()
    sr = cfg["sr"]
    y, sr = librosa.load(path, sr=sr, mono=True, dtype=np.float32)
    dur = len(y) / sr
    if dur < MIN_DURATION:
        return _result(dur, None, [], "too_short")
    long_track = dur >= cfg["long_from"]
    min_len = float(min_section) if min_section else cfg["min_section_long" if long_track else "min_section_short"]
    prom = float(prominence) if prominence else cfg["prominence_long" if long_track else "prominence_short"]

    if not np.any(np.abs(y) > 1e-4):
        return _result(dur, None, [], "no_structure")

    if _noise_like(y, sr, cfg["noise_flatness"]):
        return _result(dur, None, [], "no_structure")

    tempo, beats = librosa.beat.beat_track(y=y, sr=sr, hop_length=HOP)
    beats = np.asarray(beats)
    tempo = round(float(np.atleast_1d(tempo)[0]), 1)
    if len(beats) < 32:
        beats = np.arange(0, len(y) // HOP, int(sr * 0.5 / HOP))
    mf, ch = _features(y, sr, cfg)
    del y
    mf = librosa.util.sync(mf, beats, aggregate=np.median)
    ch = librosa.util.sync(ch, beats, aggregate=np.median)
    X = np.vstack([_z(mf[1:]), 1.5 * _z(ch)])
    X = scipy.ndimage.uniform_filter1d(X, size=4, axis=1)
    n = X.shape[1]
    D = librosa.segment.recurrence_matrix(X, mode="affinity", metric="cosine", sym=True, width=3)
    S = D.toarray() if hasattr(D, "toarray") else D

    L = cfg["kernel_beats"]
    g = np.exp(-0.5 * (np.linspace(-1, 1, 2 * L) ** 2) / 0.35**2)
    sign = np.sign(np.arange(2 * L) - L + 0.5)
    K = np.outer(g, g) * np.outer(sign, sign)
    Sp = np.pad(S, L, mode="edge")
    nov = np.array([np.sum(Sp[i : i + 2 * L, i : i + 2 * L] * K) for i in range(n)])
    nov = np.maximum(nov, 0)
    nov = nov / (nov.max() + 1e-9)

    bt = librosa.frames_to_time(beats, sr=sr, hop_length=HOP)
    bt = bt[:n] if len(bt) >= n else np.pad(bt, (0, n - len(bt)), mode="edge")
    avg_beat = np.median(np.diff(bt)) if len(bt) > 1 else 0.5
    dist = max(1, int(min_len / max(avg_beat, 1e-3)))
    peaks, _ = scipy.signal.find_peaks(nov, distance=dist, prominence=prom)
    cuts = sorted({round(c, 2) for c in [0.0] + [float(bt[p]) for p in peaks if min_len <= bt[p] <= dur - min_len] + [dur]})
    if len(cuts) < 3:
        return _result(dur, tempo, [], "no_structure")
    segs = [(cuts[i], cuts[i + 1]) for i in range(len(cuts) - 1)]
    labels, conf = _letters(X, bt, segs, cfg["label_threshold"])
    sections = [{"start": a, "end": b, "label": l, "confidence": c} for (a, b), l, c in zip(segs, labels, conf)]
    if len({s["label"] for s in sections}) < 2:
        return _result(dur, tempo, [], "no_structure")  # cuts were found but every part sounds the same: steady texture
    return _result(dur, tempo, sections)


def _letters(X, bt, segs, threshold):
    """Repetition letters (A B A C ...): agglomerative clustering of the segment means. Confidence is the best cosine
    similarity to a segment with the same letter, 0 when the letter is unique (then label stays the letter but the
    UI should not claim a repetition)."""
    import sklearn.cluster

    means = []
    for a, b in segs:
        m = (bt >= a) & (bt < b)
        means.append(X[:, m].mean(1) if m.any() else X.mean(1))
    M = np.array(means)
    M = M / (np.linalg.norm(M, axis=1, keepdims=True) + 1e-9)
    cl = sklearn.cluster.AgglomerativeClustering(n_clusters=None, distance_threshold=threshold, metric="cosine", linkage="average").fit(M)
    order = {}
    for l in cl.labels_:
        order.setdefault(l, chr(65 + len(order) % 26))
    sim = M @ M.T
    labels, conf = [], []
    for i, l in enumerate(cl.labels_):
        same = [j for j in range(len(segs)) if cl.labels_[j] == l and j != i]
        labels.append(order[l])
        conf.append(round(float(max(sim[i, same])), 2) if same else 0.0)
    return labels, conf
