"""Structure service: POST /structure (an audio file, a mono WAV is what the Laravel app sends) answers the sections of
one track; GET /health says what runs. The Laravel app (App\\Services\\Structure\\StructureClient) is the only client.
One request at a time: a track takes a large part of a small machine's RAM."""

import os
import tempfile
import threading
from contextlib import asynccontextmanager

import detect
from fastapi import FastAPI, File, Form, HTTPException, UploadFile

MAX_DURATION = float(os.environ.get("STRUCTURE_MAX_DURATION", 1200))
MAX_UPLOAD_MB = int(os.environ.get("STRUCTURE_MAX_UPLOAD_MB", 400))

_lock = threading.Lock()


def _warm_up():
    """Pays numba's compilation (about 12 s) at start instead of on the first request."""
    import numpy as np
    import soundfile

    sr = 22050
    t = np.arange(sr * 40) / sr
    y = (np.sin(2 * np.pi * 220 * t) * (1 + np.sin(2 * np.pi * 2 * t))).astype("float32") * 0.3
    with tempfile.NamedTemporaryFile(suffix=".wav") as f:
        soundfile.write(f.name, y, sr)
        detect.analyze(f.name)


@asynccontextmanager
async def lifespan(_app):
    if os.environ.get("STRUCTURE_WARMUP", "1") == "1":
        with _lock:
            _warm_up()
    yield


app = FastAPI(title="ReMoment structure", lifespan=lifespan)


@app.get("/health")
def health():
    cfg = detect.settings()
    return {"ok": True, "version": detect.VERSION, "method": detect.METHOD, "sr": cfg["sr"], "chroma": cfg["chroma"], "max_duration": MAX_DURATION}


@app.post("/structure")
def structure(audio: UploadFile = File(...), min_section: float | None = Form(None), prominence: float | None = Form(None), method: str | None = Form(None)):
    if method not in (None, "", detect.METHOD):
        raise HTTPException(422, f"Unknown method {method!r}; this service has {detect.METHOD!r}.")
    if min_section is not None and not 2 <= min_section <= 120:
        raise HTTPException(422, "min_section must be between 2 and 120 seconds.")
    suffix = os.path.splitext(audio.filename or "")[1] or ".wav"
    with tempfile.NamedTemporaryFile(suffix=suffix) as tmp:
        size = 0
        while chunk := audio.file.read(1 << 20):
            size += len(chunk)
            if size > MAX_UPLOAD_MB << 20:
                raise HTTPException(413, f"The upload is larger than {MAX_UPLOAD_MB} MB.")
            tmp.write(chunk)
        tmp.flush()
        try:
            duration = detect.duration_of(tmp.name)
        except Exception as e:  # noqa: BLE001 - not an audio file libsndfile can read
            raise HTTPException(422, f"Cannot read the audio (send a WAV): {str(e).splitlines()[0][:200]}")
        if duration > MAX_DURATION:
            raise HTTPException(
                413,
                f"The track is {duration / 60:.1f} minutes; this service takes at most {MAX_DURATION / 60:.0f} minutes (STRUCTURE_MAX_DURATION).",
            )
        with _lock:
            return detect.analyze(tmp.name, min_section=min_section, prominence=prominence)
