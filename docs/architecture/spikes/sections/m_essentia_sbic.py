"""Method B: Essentia SBic (Bayesian Information Criterion) over pooled MFCC+HPCP frames; labels by agglomerative
clustering is NOT done here (SBic gives boundaries only). Runs inside the analysis sidecar image (python 3.11, essentia-tensorflow).
usage: m_essentia_sbic.py audio out.json"""
import sys, json, time, resource
import numpy as np
import essentia, essentia.standard as es

def run(path, min_len=12.0, cpw=0.5):
    t0 = time.time()
    sr = 22050
    y = es.MonoLoader(filename=path, sampleRate=sr)()
    dur = len(y) / sr
    out = {"method": "essentia_sbic", "duration": dur}
    if dur < 30:
        out.update(sections=[], note="too short (<30 s): no sections"); return out, t0
    frame, hop = 4096, 2048
    w = es.Windowing(type="hann"); spec = es.Spectrum(); mfcc = es.MFCC(numberCoefficients=13, inputSize=frame // 2 + 1, sampleRate=sr)
    peaks = es.SpectralPeaks(sampleRate=sr, maxPeaks=50, magnitudeThreshold=1e-5); hpcp = es.HPCP(sampleRate=sr, size=12)
    feats = []
    for fr in es.FrameGenerator(y, frameSize=frame, hopSize=hop, startFromZero=True):
        s = spec(w(fr)); _, m = mfcc(s); f, mg = peaks(s)
        feats.append(np.concatenate([m[1:], hpcp(f, mg)]))
    F = np.array(feats).T  # features x frames
    pool = int(round(0.5 * sr / hop))  # ~0.5 s per pooled frame
    n = F.shape[1] // pool
    P = F[:, :n * pool].reshape(F.shape[0], n, pool).mean(2)
    P = (P - P.mean(1, keepdims=True)) / (P.std(1, keepdims=True) + 1e-8)
    fps = sr / hop / pool
    seg = es.SBic(cpw=cpw, minLength=int(min_len * fps), size1=int(40 * fps), inc1=int(10 * fps), size2=int(20 * fps), inc2=int(2 * fps))(P.astype("float32"))
    cuts = sorted(set([0.0] + [float(s) / fps for s in seg] + [dur]))
    cuts = [c for c in cuts if c == 0.0 or c == dur or (min_len * 0.8 <= c <= dur - min_len * 0.8)]
    out["sections"] = [{"start": round(a, 2), "end": round(b, 2), "label": None, "confidence": None} for a, b in zip(cuts[:-1], cuts[1:])]
    return out, t0

if __name__ == "__main__":
    res, t0 = run(sys.argv[1])
    res["wall_s"] = round(time.time() - t0, 2)
    res["peak_rss_mb"] = round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024)  # KB on Linux
    json.dump(res, open(sys.argv[2], "w"))
    print(sys.argv[1].split("/")[-1], res["wall_s"], "s", res["peak_rss_mb"], "MB", [round(s["start"]) for s in res["sections"]])
