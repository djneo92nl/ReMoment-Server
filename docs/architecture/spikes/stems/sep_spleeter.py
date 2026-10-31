"""usage: sep_spleeter.py file out.json [chunk_s]  -> per-stem RMS dB curves (0.5 s), time, peak RSS. No audio kept.
chunk_s > 0: windows of chunk_s seconds (+2 s padding each side, dropped). Spleeter on a whole 2-minute track was OOM-killed at 7 GB."""
import sys, json, time, resource, subprocess, numpy as np
path, out = sys.argv[1:3]
chunk_s = float(sys.argv[3]) if len(sys.argv) > 3 else 0
SR, HOP, PAD = 44100, 22050, 2 * 44100
t0 = time.time()
raw = subprocess.run(["ffmpeg", "-v", "error", "-i", path, "-f", "f32le", "-ac", "2", "-ar", "44100", "-"], capture_output=True, check=True).stdout
wav = np.frombuffer(raw, np.float32).reshape(-1, 2).copy()
dur = len(wav) / SR
t_dec = time.time()
from spleeter.separator import Separator
sep = Separator("spleeter:4stems", multiprocess=False)
t_load = time.time()
stems = {}


def curve(m):
    rms = np.sqrt((m.reshape(-1, HOP) ** 2).mean(1))
    return np.round(20 * np.log10(rms + 1e-6), 1).tolist()


if chunk_s <= 0:
    res = sep.separate(wav)
    for name, s in res.items():
        m = s.mean(1); stems[name] = curve(m[:len(m) // HOP * HOP])
else:
    C = int(chunk_s * SR); pos = 0
    while pos < len(wav):
        lo = max(0, pos - PAD); hi = min(len(wav), pos + C + PAD)
        res = sep.separate(wav[lo:hi])
        end = min(len(wav), pos + C)
        keep = (end - pos) // HOP * HOP if end == len(wav) else end - pos
        for name, s in res.items():
            m = s.mean(1)[pos - lo:pos - lo + keep]
            stems.setdefault(name, []).extend(curve(m[:len(m) // HOP * HOP]))
        pos += C
t_sep = time.time()
rss = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss // 1024
json.dump({"model": "spleeter:4stems", "chunk_s": chunk_s, "duration": dur, "rate_hz": 2, "decode_s": round(t_dec - t0, 1), "load_s": round(t_load - t_dec, 1), "sep_s": round(t_sep - t_load, 1),
           "wall_s": round(t_sep - t0, 1), "peak_rss_mb": rss, "threads": 4, "stems": stems}, open(out, "w"))
print("spleeter", path, "dur", round(dur), "sep", round(t_sep - t_load, 1), "rss", rss)
