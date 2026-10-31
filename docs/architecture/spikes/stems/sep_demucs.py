"""usage: sep_demucs.py model file out.json [segment] [chunk_s] [shifts]
-> per-stem RMS dB curves (0.5 s), time, peak RSS. No audio kept.
chunk_s > 0: the track is decoded from the ffmpeg pipe and separated window by window (chunk_s plus PAD each side,
the padding is dropped), so peak RAM no longer grows with the track length. 0 = whole track in memory."""
import sys, json, time, resource, subprocess, numpy as np, torch
model_name, path, out = sys.argv[1:4]
segment = float(sys.argv[4]) if len(sys.argv) > 4 and float(sys.argv[4]) > 0 else None
chunk_s = float(sys.argv[5]) if len(sys.argv) > 5 else 0
shifts = int(sys.argv[6]) if len(sys.argv) > 6 else 0
SR, HOP, PAD = 44100, 22050, 2.0
torch.set_num_threads(4)
from demucs.pretrained import get_model
from demucs.apply import apply_model
t0 = time.time()
model = get_model(model_name); model.eval()
t_load = time.time()
kw = {"segment": segment} if segment else {}


def separate(block):  # block: (2, n) float32 tensor -> (stems, 2, n)
    ref = block.mean(0); mu, sd = ref.mean(), ref.std() + 1e-8
    with torch.no_grad():
        s = apply_model(model, ((block - mu) / sd)[None], shifts=shifts, split=True, overlap=0.25, progress=False, num_workers=0, **kw)[0]
    return s * sd + mu


def curve(m):  # (n,) numpy mono, n multiple of HOP -> dB list
    rms = np.sqrt((m.reshape(-1, HOP) ** 2).mean(1))
    return np.round(20 * np.log10(rms + 1e-6), 1).tolist()


proc = subprocess.Popen(["ffmpeg", "-v", "error", "-i", path, "-f", "f32le", "-ac", "2", "-ar", str(SR), "-"], stdout=subprocess.PIPE)
def read_frames(nf):
    b = proc.stdout.read(nf * 8)
    return np.frombuffer(b, np.float32).reshape(-1, 2) if b else np.zeros((0, 2), np.float32)

stems = {n: [] for n in model.sources}
total = 0
if chunk_s <= 0:
    data = []
    while True:
        a = read_frames(SR * 60)
        if not len(a): break
        data.append(a)
    wav = torch.from_numpy(np.concatenate(data).T.copy()); del data
    total = wav.shape[1]
    srcs = separate(wav)
    for name, s in zip(model.sources, srcs):
        m = s.mean(0).numpy(); stems[name] = curve(m[:len(m) // HOP * HOP])
else:
    C = int(chunk_s * SR); P = int(PAD * SR)
    prev = np.zeros((0, 2), np.float32)  # padding kept from the previous window
    cur = read_frames(C + P)
    start = 0
    while len(cur):
        nxt = read_frames(C)  # one window ahead so the right padding exists
        block = np.concatenate([prev, cur, nxt[:P]]) if len(nxt) else np.concatenate([prev, cur])
        srcs = separate(torch.from_numpy(block.T.copy()))
        lo = len(prev); hi = lo + len(cur) - (0 if len(nxt) else 0)
        # keep [lo, lo+len(cur)) but only whole 0.5 s frames; carry the rest (rounded down) into the next window's start
        keep = len(cur) // HOP * HOP if len(nxt) else len(cur) // HOP * HOP
        for name, s in zip(model.sources, srcs):
            m = s.mean(0).numpy()[lo:lo + keep]; stems[name] += curve(m)
        total += len(cur)
        if not len(nxt):
            break
        leftover = cur[keep:]
        prev = cur[max(0, keep - P):keep] if keep >= P else cur[:keep]
        cur = np.concatenate([leftover, nxt]) if len(leftover) else nxt
        # pad exactly the window sizes: next window starts at a frame boundary because prev ended at `keep`
        del srcs, block
dur = total / SR
t_sep = time.time()
rss = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss // 1024
json.dump({"model": model_name, "segment": segment, "chunk_s": chunk_s, "shifts": shifts, "duration": dur, "rate_hz": 2, "load_s": round(t_load - t0, 1),
           "sep_s": round(t_sep - t_load, 1), "wall_s": round(t_sep - t0, 1), "peak_rss_mb": rss,
           "threads": 4, "torch": torch.__version__, "stems": stems}, open(out, "w"))
print(model_name, path, "dur", round(dur), "sep", round(t_sep - t_load, 1), "rss", rss, "frames", len(stems["vocals"]))
