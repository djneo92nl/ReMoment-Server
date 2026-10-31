"""usage: master.py file out.json -> RMS dB curve of the mix per 0.5 s (same grid as the stems)."""
import sys, json, subprocess, numpy as np
path, out = sys.argv[1:3]
raw = subprocess.run(["ffmpeg", "-v", "error", "-i", path, "-f", "f32le", "-ac", "1", "-ar", "44100", "-"], capture_output=True, check=True).stdout
m = np.frombuffer(raw, np.float32); hop = 22050; n = len(m) // hop
rms = np.sqrt((m[:n * hop].reshape(n, hop) ** 2).mean(1))
json.dump({"duration": len(m) / 44100, "mix": np.round(20 * np.log10(rms + 1e-6), 1).tolist()}, open(out, "w"))
