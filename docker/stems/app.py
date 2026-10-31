"""Stems service: POST /stems (an audio file) answers per-stem loudness curves and instrumental ranges of one track;
GET /health says what runs. The Laravel app (App\\Domain\\Analysis\\Stages\\StemsStage) is the only client.
One request at a time: a track takes a large part of a small machine's RAM and every core for minutes."""

import os
import tempfile
import threading
from contextlib import asynccontextmanager

import separate
from fastapi import FastAPI, File, HTTPException, UploadFile

MAX_DURATION = float(os.environ.get("STEMS_MAX_DURATION", 1200))
MAX_UPLOAD_MB = int(os.environ.get("STEMS_MAX_UPLOAD_MB", 600))

_lock = threading.Lock()
_load_error: str | None = None


def _load():
    global _load_error
    try:
        separate.load_model()
        _load_error = None
    except Exception as e:  # noqa: BLE001 - offline without cached weights, a broken volume, ...
        _load_error = str(e).splitlines()[0][:200] if str(e) else e.__class__.__name__


@asynccontextmanager
async def lifespan(_app):
    if os.environ.get("STEMS_PRELOAD", "1") == "1":
        threading.Thread(target=_load, daemon=True).start()
    yield


app = FastAPI(title="ReMoment stems", lifespan=lifespan)


@app.get("/health")
def health():
    """`ok` once the model is loaded; a service that cannot fetch its weights says why in `error` and is not ok."""
    ok = separate.model_loaded()

    return {
        "ok": ok,
        "version": separate.VERSION,
        "method": separate.METHOD,
        "rate_hz": separate.RATE_HZ,
        "max_duration": MAX_DURATION,
        "loading": not ok and _load_error is None,
        "error": _load_error,
    }


@app.post("/stems")
def stems(audio: UploadFile = File(...)):
    suffix = os.path.splitext(audio.filename or "")[1] or ".audio"
    with tempfile.NamedTemporaryFile(suffix=suffix) as tmp:
        size = 0
        while chunk := audio.file.read(1 << 20):
            size += len(chunk)
            if size > MAX_UPLOAD_MB << 20:
                raise HTTPException(413, f"The upload is larger than {MAX_UPLOAD_MB} MB.")
            tmp.write(chunk)
        tmp.flush()
        try:
            duration = separate.duration_of(tmp.name)
        except Exception as e:  # noqa: BLE001 - not audio ffmpeg can read
            raise HTTPException(422, f"Cannot read the audio: {str(e)[:200]}")
        if duration > MAX_DURATION:
            raise HTTPException(413, f"The track is {duration / 60:.1f} minutes; this service takes at most {MAX_DURATION / 60:.0f} minutes (STEMS_MAX_DURATION).")
        with _lock:
            if not separate.model_loaded():
                _load()
            if not separate.model_loaded():
                raise HTTPException(503, f"The model is not available: {_load_error or 'still loading'}")
            try:
                return separate.analyze(tmp.name)
            except ValueError as e:
                raise HTTPException(422, str(e))
