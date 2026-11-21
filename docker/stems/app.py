"""Stems service: POST /stems (an audio file) answers per-stem loudness curves and instrumental ranges of one track;
GET /health says what runs. The Laravel app (App\\Domain\\Analysis\\Stages\\StemsStage) is the only client.
One request at a time: a track takes a large part of a small machine's RAM and every core for minutes."""

import json
import os
import shutil
import tempfile
import threading
import zipfile
from contextlib import asynccontextmanager

import separate
import weights
from fastapi import FastAPI, File, HTTPException, Query, UploadFile
from fastapi.responses import FileResponse, JSONResponse
from starlette.background import BackgroundTask

MAX_DURATION = float(os.environ.get("STEMS_MAX_DURATION", 1200))
MAX_UPLOAD_MB = int(os.environ.get("STEMS_MAX_UPLOAD_MB", 600))

_lock = threading.Lock()
_load_error: str | None = None


def _load():
    global _load_error
    try:
        separate.load_model()
        _load_error = None
    except weights.WeightsMissing:
        _load_error = None  # not an error: the weights are opt-in, `weights` in /health says where they stand
    except Exception as e:  # noqa: BLE001 - offline without cached weights, a broken volume, ...
        _load_error = str(e).splitlines()[0][:200] if str(e) else e.__class__.__name__


@asynccontextmanager
async def lifespan(_app):
    if os.environ.get("STEMS_PRELOAD", "1") == "1":
        # A download finishing loads the model right away; weights already on the volume load now. Nothing downloads by itself.
        separate.WEIGHTS.on_ready = lambda: threading.Thread(target=_load, daemon=True).start()
        threading.Thread(target=_load, daemon=True).start()
    yield


app = FastAPI(title="ReMoment stems", lifespan=lifespan)


@app.get("/health")
def health():
    """`ok` once the model is loaded. `weights` is missing | downloading | ready | failed: the weights are downloaded only on
    request (POST /weights), so a fresh service is not ok and has no `error`; `error` is a model that could not load."""
    ok = separate.model_loaded()
    state = separate.WEIGHTS.state()

    return {
        "ok": ok,
        "version": separate.VERSION,
        "method": separate.METHOD,
        "stems": list(separate.SOURCES),
        "rate_hz": separate.RATE_HZ,
        "max_duration": MAX_DURATION,
        "audio_formats": separate.audio_formats(),
        "weights": state,
        "loading": not ok and _load_error is None and state == weights.READY,
        "error": _load_error,
    }


@app.get("/weights")
def weights_status():
    """Where the opt-in model download stands: state missing | downloading | ready | failed, sizes, source, checksum, error."""
    return separate.WEIGHTS.status()


@app.post("/weights", status_code=202)
def weights_download():
    """Starts the download of the weights (pinned URL, SHA-256 checked) unless they are there or arriving. Idempotent: answers
    the status, 202 while one is under way or just started, 200 when they were ready already."""
    status = separate.WEIGHTS.start()

    return JSONResponse(status, status_code=200 if status["state"] == weights.READY else 202)


@app.post("/stems")
def stems(audio: UploadFile = File(...), audio_format: str | None = Query(None, alias="audio"), bitrate: int = Query(separate.DEFAULT_BITRATE, ge=64, le=320)):
    """The curves as JSON; with `audio` (mp3 | flac) a zip (stored) of stems.json and one audio file per stem."""
    if not separate.model_loaded():
        try:
            separate.WEIGHTS.require()
        except weights.WeightsMissing as e:
            raise HTTPException(503, str(e))
    if audio_format is not None and audio_format not in separate.audio_formats():
        raise HTTPException(422, f"Cannot encode {audio_format} here; this ffmpeg makes: {', '.join(separate.audio_formats()) or 'nothing'}.")
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
            if audio_format is None:
                try:
                    return separate.analyze(tmp.name)
                except ValueError as e:
                    raise HTTPException(422, str(e))

            return _with_audio(tmp.name, audio_format, bitrate)


def _with_audio(path: str, fmt: str, bitrate: int) -> FileResponse:
    """Separates once, encodes the four stems while doing so, answers a zip: stems.json (the usual answer plus an
    `audio` block) and `{stem}.{ext}`. The work directory is removed after the response was sent."""
    work = tempfile.mkdtemp(prefix="stems-")
    try:
        encoders = separate.StemEncoders(work, fmt, bitrate)
        try:
            result = separate.analyze(path, encoders)
            encoders.close()
        except BaseException:
            encoders.abort()
            raise
        names = {name: os.path.basename(p) for name, p in encoders.files.items()}
        info = {"format": fmt, "bitrate": encoders.bitrate, "sample_rate": separate.SR, "channels": 2, "files": names}
        archive = os.path.join(work, "stems.zip")
        with zipfile.ZipFile(archive, "w", zipfile.ZIP_STORED) as z:
            z.writestr("stems.json", json.dumps({**result, "audio": info}))
            for p in encoders.files.values():
                z.write(p, os.path.basename(p))
        return FileResponse(archive, media_type="application/zip", filename="stems.zip", background=BackgroundTask(shutil.rmtree, work, True))
    except ValueError as e:
        shutil.rmtree(work, True)
        raise HTTPException(422, str(e))
    except BaseException:
        shutil.rmtree(work, True)
        raise
