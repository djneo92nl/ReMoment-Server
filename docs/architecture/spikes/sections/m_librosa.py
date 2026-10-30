"""Method A: beat-synchronous MFCC+chroma, Foote checkerboard novelty on a recurrence/self-similarity matrix,
peak picking with a minimum section length, then agglomerative clustering of the segments for letters.
usage: m_librosa.py audio.ext out.json"""
import sys, json, time, resource
import numpy as np, librosa, scipy.ndimage, scipy.signal

def run(path, min_len=12.0, kernel_beats=16, prominence=0.2):
    t0 = time.time()
    y, sr = librosa.load(path, sr=22050, mono=True)
    dur = len(y) / sr
    out = {"method": "librosa_novelty", "duration": dur}
    if dur < 30:
        out.update(sections=[], note="too short (<30 s): no sections"); return out, t0
    hop = 512
    tempo, beats = librosa.beat.beat_track(y=y, sr=sr, hop_length=hop)
    beats = np.asarray(beats)
    if len(beats) < 32: beats = np.arange(0, len(y)//hop, int(sr*0.5/hop))
    mf = librosa.feature.mfcc(y=y, sr=sr, n_mfcc=20, hop_length=hop)
    ch = librosa.feature.chroma_cqt(y=y, sr=sr, hop_length=hop)
    mf = librosa.util.sync(mf, beats, aggregate=np.median)
    ch = librosa.util.sync(ch, beats, aggregate=np.median)
    def z(x): return (x - x.mean(1, keepdims=True)) / (x.std(1, keepdims=True) + 1e-8)
    # stack with a little temporal memory, the usual trick for structure
    X = np.vstack([z(mf[1:]), 1.5 * z(ch)])
    X = scipy.ndimage.uniform_filter1d(X, size=4, axis=1)
    n = X.shape[1]
    D = librosa.segment.recurrence_matrix(X, mode="affinity", metric="cosine", sym=True, width=3)
    S = D.toarray() if hasattr(D, "toarray") else D
    # checkerboard kernel novelty along the diagonal
    L = kernel_beats
    g = np.exp(-0.5 * (np.linspace(-1, 1, 2 * L) ** 2) / 0.35 ** 2)
    K = np.outer(g, g) * np.outer(np.sign(np.arange(2 * L) - L + 0.5), np.sign(np.arange(2 * L) - L + 0.5))
    Sp = np.pad(S, L, mode="edge")
    nov = np.array([np.sum(Sp[i:i + 2 * L, i:i + 2 * L] * K) for i in range(n)])
    nov = np.maximum(nov, 0); nov = nov / (nov.max() + 1e-9)
    bt = librosa.frames_to_time(beats, sr=sr, hop_length=hop)
    bt = bt[:n] if len(bt) >= n else np.pad(bt, (0, n - len(bt)), mode="edge")
    avg_beat = np.median(np.diff(bt))
    dist = max(1, int(min_len / avg_beat))
    peaks, props = scipy.signal.find_peaks(nov, distance=dist, prominence=prominence)
    cuts = [0.0] + [float(bt[p]) for p in peaks if min_len <= bt[p] <= dur - min_len] + [dur]
    # enforce min length by dropping the weaker of two close cuts
    cuts = sorted(set(round(c, 2) for c in cuts))
    segs = [(cuts[i], cuts[i + 1]) for i in range(len(cuts) - 1)]
    # labels: agglomerative on segment mean features, threshold on cosine distance
    labels, conf = [], []
    if len(segs) >= 2:
        import sklearn.cluster  # librosa dependency
        means = []
        for a, b in segs:
            m = (bt >= a) & (bt < b)
            means.append(X[:, m].mean(1) if m.any() else X.mean(1))
        M = np.array(means); M = M / (np.linalg.norm(M, axis=1, keepdims=True) + 1e-9)
        cl = sklearn.cluster.AgglomerativeClustering(n_clusters=None, distance_threshold=0.6, metric="cosine", linkage="average").fit(M)
        order = {}
        for l in cl.labels_: order.setdefault(l, chr(65 + len(order)))
        labels = [order[l] for l in cl.labels_]
        sim = M @ M.T
        for i, l in enumerate(cl.labels_):
            same = [j for j in range(len(segs)) if cl.labels_[j] == l and j != i]
            conf.append(round(float(max(sim[i, same])), 2) if same else 0.0)
    else:
        labels, conf = ["A"] * len(segs), [0.0] * len(segs)
    out["tempo"] = float(np.atleast_1d(tempo)[0])
    out["sections"] = [{"start": a, "end": b, "label": l, "confidence": c} for (a, b), l, c in zip(segs, labels, conf)]
    out["novelty"] = [round(float(v), 3) for v in nov]; out["novelty_t"] = [round(float(v), 2) for v in bt]
    return out, t0

if __name__ == "__main__":
    ml = float(sys.argv[3]) if len(sys.argv) > 3 else 12.0
    pr = float(sys.argv[4]) if len(sys.argv) > 4 else 0.2
    res, t0 = run(sys.argv[1], min_len=ml, prominence=pr)
    res["wall_s"] = round(time.time() - t0, 2)
    res["peak_rss_mb"] = round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1e6, 0)  # bytes on macOS
    json.dump(res, open(sys.argv[2], "w"))
    print(sys.argv[1].split("/")[-1], res["wall_s"], "s", res["peak_rss_mb"], "MB", [round(s["start"]) for s in res["sections"]])
