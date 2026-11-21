"""Stems of ONE track as loudness curves (docs/architecture/stems-service.md).

The track is decoded by ffmpeg to 44.1 kHz stereo and separated by Demucs (htdemucs, or htdemucs_6s) window by window: WINDOW seconds
of audio plus PAD seconds on each side, the padding is dropped. That keeps the peak RAM flat (about 1.5 GB) for any
track length (the spike measured 1.2 GB + 3.5 MB per second of audio when the whole track is separated at once). Only a
loudness curve per stem leaves this module, never audio: an integer dB value (RMS of the stem's mono mix over one
frame, relative to full scale, clamped to FLOOR..0) every 1 / RATE_HZ seconds. On request (`StemEncoders`) each stem's
stereo samples also go to one ffmpeg per stem as the windows are separated, so a stem file is one continuous encode
(no seams) and the RAM stays flat.
"""

import os
import subprocess
import threading
from functools import lru_cache

import numpy as np
import weights

VERSION = 1
# STEMS_MODEL picks the model: `htdemucs` (four stems, the default) or `htdemucs_6s` (adds guitar and piano; the piano stem
# is the weakest, and the weights' licence is likely non-commercial, see docs/architecture/track-stems-spike.md).
MODELS = {
    "htdemucs": ("vocals", "drums", "bass", "other"),
    "htdemucs_6s": ("vocals", "drums", "bass", "guitar", "piano", "other"),
}
METHOD = os.environ.get("STEMS_MODEL", "htdemucs").strip() or "htdemucs"
if METHOD not in MODELS:
    raise ValueError(f"STEMS_MODEL must be one of {', '.join(MODELS)} (got {METHOD!r})")
SOURCES = MODELS[METHOD]
SR = 44100
RATE_HZ = 2
HOP = SR // RATE_HZ  # samples per frame
WINDOW = 60 * SR  # samples per core window; a multiple of HOP, so every window but the last is whole frames
PAD = 2 * SR
FLOOR = -80
CEIL = 0

# Instrumental detection (measured in the spike): a frame is quiet when the median-smoothed vocals curve is more than
# DROP dB under the track's own 90th percentile; quiet runs closer than MERGE seconds join; a run of at least MIN_LEN
# seconds is an instrumental range. A track whose vocals never reach VOCAL_FLOOR dB has no vocals at all.
DROP = 20
MIN_LEN = 8.0
MERGE = 3.0
SMOOTH = 5
VOCAL_FLOOR = -50

# The weights of the configured model: downloaded only on request (weights.py), never by the model loader itself.
WEIGHTS = weights.Weights(METHOD)

_model = None
_model_lock = threading.Lock()


def load_model():
    """The configured model (METHOD), loaded once from the weights the admin downloaded (WEIGHTS); raises
    weights.WeightsMissing, without touching the network, while they are not there."""
    global _model
    with _model_lock:
        if _model is None:
            WEIGHTS.require()
            import torch
            from demucs.pretrained import get_model

            torch.set_num_threads(int(os.environ.get("STEMS_THREADS", min(4, os.cpu_count() or 1))))
            m = get_model(METHOD)
            m.eval()
            if sorted(m.sources) != sorted(SOURCES):
                raise RuntimeError(f"Unexpected sources {m.sources}")
            _model = m
    return _model


def model_loaded() -> bool:
    return _model is not None


def separate_block(block: np.ndarray) -> np.ndarray:
    """(n, 2) float32 samples -> (len(SOURCES), n, 2) stereo stems in SOURCES order. Replaced by a fake in the tests."""
    import torch
    from demucs.apply import apply_model

    model = load_model()
    wav = torch.from_numpy(np.ascontiguousarray(block.T))
    ref = wav.mean(0)
    mu, sd = ref.mean(), ref.std() + 1e-8
    with torch.no_grad():
        out = apply_model(model, ((wav - mu) / sd)[None], shifts=0, split=True, overlap=0.25, progress=False, num_workers=0)[0]
    stereo = (out * sd + mu).numpy()  # (sources, 2, n)
    return np.stack([stereo[list(model.sources).index(name)].T for name in SOURCES])  # the model's own order differs (htdemucs: drums, bass, other, vocals)


AUDIO_FORMATS = {"mp3": ("libmp3lame", "mp3"), "flac": ("flac", "flac")}
DEFAULT_BITRATE = 192


@lru_cache(maxsize=1)
def audio_formats() -> list[str]:
    """The stem audio formats this ffmpeg can encode (mp3 needs libmp3lame)."""
    try:
        listing = subprocess.run(["ffmpeg", "-hide_banner", "-encoders"], capture_output=True, text=True).stdout
    except OSError:
        return []

    return [fmt for fmt, (codec, _) in AUDIO_FORMATS.items() if f" {codec} " in listing]


class StemEncoders:
    """One ffmpeg per stem, fed stereo float samples as they come out of the separator; files land in `directory`."""

    def __init__(self, directory: str, fmt: str = "mp3", bitrate: int = DEFAULT_BITRATE):
        if fmt not in AUDIO_FORMATS:
            raise ValueError(f"Unknown audio format {fmt}.")
        codec, ext = AUDIO_FORMATS[fmt]
        self.format = fmt
        self.bitrate = bitrate if fmt == "mp3" else None
        self.files = {name: os.path.join(directory, f"{name}.{ext}") for name in SOURCES}
        extra = ["-b:a", f"{bitrate}k"] if fmt == "mp3" else ["-sample_fmt", "s16"]
        self._procs = {
            name: subprocess.Popen(
                ["ffmpeg", "-v", "error", "-nostdin", "-y", "-f", "f32le", "-ar", str(SR), "-ac", "2", "-i", "-", "-c:a", codec, *extra, path],
                stdin=subprocess.PIPE, stderr=subprocess.PIPE,
            )
            for name, path in self.files.items()
        }

    def write(self, name: str, samples: np.ndarray) -> None:
        """(n, 2) float samples of one stem."""
        self._procs[name].stdin.write(np.clip(samples, -1, 1).astype("<f4").tobytes())

    def close(self) -> None:
        """Finishes every file; raises when an encoder failed."""
        errors = []
        for name, proc in self._procs.items():
            try:
                proc.stdin.close()
            except BrokenPipeError:
                pass
            err = proc.stderr.read().decode(errors="replace").strip()
            if proc.wait() != 0:
                errors.append(f"{name}: {err.splitlines()[-1][:150] if err else 'ffmpeg failed'}")
        if errors:
            raise RuntimeError("Encoding the stems failed: " + "; ".join(errors))

    def abort(self) -> None:
        for proc in self._procs.values():
            proc.kill()
            proc.wait()


def frames_db(mono: np.ndarray) -> list[int]:
    """Integer dB per HOP-sample frame (a partial last frame is dropped)."""
    n = len(mono) // HOP
    if n == 0:
        return []
    rms = np.sqrt((mono[: n * HOP].astype(np.float64).reshape(n, HOP) ** 2).mean(1))
    db = 20 * np.log10(rms + 1e-6)
    return np.clip(np.round(db), FLOOR, CEIL).astype(int).tolist()


def _read(proc, samples: int) -> np.ndarray:
    data = proc.stdout.read(samples * 8)  # 2 channels x float32
    return np.frombuffer(data, np.float32).reshape(-1, 2) if data else np.zeros((0, 2), np.float32)


def curves(path: str, window: int = WINDOW, pad: int = PAD, encoders: StemEncoders | None = None) -> tuple[float, dict[str, list[int]]]:
    """-> (duration in seconds, {stem: [dB per frame]}); the audio is decoded as a stream, a window at a time.
    With `encoders` every stem's stereo samples are also encoded (the caller closes the encoders)."""
    assert window % HOP == 0
    proc = subprocess.Popen(["ffmpeg", "-v", "error", "-nostdin", "-i", path, "-f", "f32le", "-ac", "2", "-ar", str(SR), "-"], stdout=subprocess.PIPE)
    out: dict[str, list[int]] = {s: [] for s in SOURCES}
    total = 0
    try:
        prev = np.zeros((0, 2), np.float32)
        cur = _read(proc, window)
        while len(cur):
            nxt = _read(proc, window)
            block = np.concatenate([prev, cur, nxt[:pad]])
            stems = separate_block(block)
            for name, stereo in zip(SOURCES, stems):
                core = stereo[len(prev) : len(prev) + len(cur)]
                out[name] += frames_db(core.mean(1))
                if encoders is not None:
                    encoders.write(name, core)
            total += len(cur)
            prev = cur[-pad:]
            cur = nxt
    finally:
        proc.stdout.close()
        proc.kill()
        proc.wait()
    if total == 0:
        raise ValueError("No audio could be decoded.")
    return total / SR, out


def _median(values: list[int], k: int = SMOOTH) -> list[int]:
    h = k // 2
    return [sorted(values[max(0, i - h) : i + h + 1])[len(values[max(0, i - h) : i + h + 1]) // 2] for i in range(len(values))]


def instrumental(vocals: list[int]) -> tuple[float, list[dict]]:
    """-> (vocal_ratio 0..1, [{start, end}] in seconds) from the vocals curve alone."""
    n = len(vocals)
    if n == 0:
        return 0.0, []
    v = _median(vocals)
    ref = sorted(v)[min(n - 1, round(0.9 * (n - 1)))]
    if ref < VOCAL_FLOOR:
        return 0.0, [{"start": 0.0, "end": round(n / RATE_HZ, 2)}]
    quiet = [x < ref - DROP for x in v]
    runs: list[list[int]] = []
    i = 0
    while i < n:
        if quiet[i]:
            j = i
            while j < n and quiet[j]:
                j += 1
            if runs and (i - runs[-1][1]) / RATE_HZ < MERGE:
                runs[-1][1] = j
            else:
                runs.append([i, j])
            i = j
        else:
            i += 1
    ranges = [{"start": round(a / RATE_HZ, 2), "end": round(b / RATE_HZ, 2)} for a, b in runs if (b - a) / RATE_HZ >= MIN_LEN]
    silent = sum(r["end"] - r["start"] for r in ranges)
    return round(max(0.0, 1 - silent / (n / RATE_HZ)), 3), ranges


def analyze(path: str, encoders: StemEncoders | None = None) -> dict:
    duration, stems = curves(path, encoders=encoders)
    ratio, ranges = instrumental(stems["vocals"])

    return {
        "version": VERSION,
        "duration": round(duration, 2),
        "method": METHOD,
        "rate_hz": RATE_HZ,
        "stems": stems,
        "vocal_ratio": ratio,
        "instrumental": ranges,
    }


def duration_of(path: str) -> float:
    """Seconds, from ffprobe; raises when ffmpeg cannot read the file."""
    r = subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", path], capture_output=True, text=True)
    try:
        return float(r.stdout.strip())
    except ValueError:
        raise ValueError((r.stderr or "not an audio file").strip().splitlines()[0][:200] if (r.stderr or "").strip() else "not an audio file")
