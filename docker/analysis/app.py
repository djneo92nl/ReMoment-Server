"""Essentia analysis sidecar: POST /analyze (a mono WAV) answers BPM, key, danceability and, when the
TensorFlow models are installed, mood, arousal/valence, voice/instrumental and genre, plus the
Discogs-EffNet embedding of the track (the mean over its patches, for "similar tracks"). POST /curve (arousal and valence per
window of a few seconds, workstream 16.3) and POST /section-embeddings (one EffNet vector per time range, 16.5) keep the per-patch
predictions /analyze averages away. GET /health says what is available. The Laravel app (App\\Domain\\Analysis\\Stages\\EssentiaStage) is the only client."""

import json
import os
import shutil
import tempfile
import threading

import essentia
import essentia.standard as es
from fastapi import FastAPI, File, Form, HTTPException, UploadFile

import curve

MODEL_DIR = os.environ.get("MODEL_DIR", "/models")
WANT_ML = os.environ.get("ESSENTIA_ML") == "1"

MOODS = ["happy", "sad", "aggressive", "relaxed", "party", "acoustic", "electronic"]
FLATS = {"Db": "C#", "Eb": "D#", "Gb": "F#", "Ab": "G#", "Bb": "A#"}

app = FastAPI(title="ReMoment analysis")
# One analysis at a time: a track takes the CPU of a small machine for a while.
_lock = threading.Lock()
_models = {}


def _path(name):
    return os.path.join(MODEL_DIR, name)


def _classes(name):
    """The class names of a model, from its metadata json next to the graph."""
    try:
        with open(_path(name + ".json")) as f:
            return json.load(f).get("classes", [])
    except (OSError, ValueError):
        return []


_errors = {}


def _schema(name):
    """The graph's input and output node names, from the `schema` of its metadata json (models differ)."""
    try:
        with open(_path(name + ".json")) as f:
            schema = json.load(f).get("schema", {})
    except (OSError, ValueError):
        return None, None, []
    inputs = [i.get("name") for i in schema.get("inputs", [])]
    outputs = schema.get("outputs", [])
    return (inputs[0] if inputs else None), (outputs[0].get("name") if outputs else None), outputs


def _try(key, build):
    """Builds one model; a failure is remembered for /health and skips that model only."""
    try:
        _models[key] = build()
    except Exception as e:  # noqa: BLE001 - any Essentia/TensorFlow error just drops this model
        _errors[key] = str(e).splitlines()[0][:300]


def _predictor(name):
    """A TensorflowPredict2D classifier head, configured with the node names its json declares."""
    from essentia.standard import TensorflowPredict2D
    inp, out, _ = _schema(name)
    kwargs = {"graphFilename": _path(name + ".pb")}
    if inp:
        kwargs["input"] = inp
    if out:
        kwargs["output"] = out
    return TensorflowPredict2D(**kwargs)


def _load_models():
    """Loads whatever models are present; a missing or broken one is skipped, not fatal."""
    if not WANT_ML:
        return
    try:
        from essentia.standard import TensorflowPredictEffnetDiscogs, TensorflowPredictMusiCNN
    except ImportError:
        return

    if os.path.exists(_path("discogs-effnet-bs64-1.pb")):
        def effnet():
            # The embedding is the output the json calls "embeddings"; the first one is the logits.
            _, out, outputs = _schema("discogs-effnet-bs64-1")
            for o in outputs:
                if "embedding" in str(o.get("output_purpose", "")).lower() or "embedding" in str(o.get("description", "")).lower():
                    out = o.get("name")
            return TensorflowPredictEffnetDiscogs(graphFilename=_path("discogs-effnet-bs64-1.pb"), output=out or "PartitionedCall:1")

        _try("effnet", effnet)

        if "effnet" in _models:
            heads = [f"mood_{m}-discogs-effnet-1" for m in MOODS] + [
                "danceability-discogs-effnet-1", "voice_instrumental-discogs-effnet-1", "genre_discogs400-discogs-effnet-1"]
            for head in heads:
                if os.path.exists(_path(head + ".pb")):
                    _try(head, lambda head=head: _predictor(head))

    if os.path.exists(_path("msd-musicnn-1.pb")) and os.path.exists(_path("deam-msd-musicnn-2.pb")):
        def musicnn():
            _, out, outputs = _schema("msd-musicnn-1")
            for o in outputs:
                if "embedding" in str(o.get("output_purpose", "")).lower() or "embedding" in str(o.get("description", "")).lower():
                    out = o.get("name")
            return TensorflowPredictMusiCNN(graphFilename=_path("msd-musicnn-1.pb"), output=out or "model/dense/BiasAdd")

        _try("musicnn", musicnn)
        if "musicnn" in _models:
            _try("deam", lambda: _predictor("deam-msd-musicnn-2"))


_load_models()


def _classic(path):
    audio = es.MonoLoader(filename=path, sampleRate=44100)()
    bpm = es.RhythmExtractor2013(method="multifeature")(audio)[0]
    key, scale, strength = es.KeyExtractor()(audio)
    danceability = es.Danceability()(audio)[0]
    return {
        "bpm": float(bpm),
        "key": FLATS.get(key, key),
        "mode": scale,
        "key_strength": float(strength),
        # Essentia's classic danceability is roughly 0-3.
        "danceability": max(0.0, min(1.0, float(danceability) / 3.0)),
    }


def _positive(head, activations, positive):
    """Mean probability of the class named `positive` over the track."""
    classes = _classes(head) or [positive]
    index = classes.index(positive) if positive in classes else 0
    return float(activations.mean(axis=0)[index])


def _ml(path):
    out = {}
    if "effnet" in _models:
        audio = es.MonoLoader(filename=path, sampleRate=16000, resampleQuality=4)()
        embeddings = _models["effnet"](audio)

        # One 1280-number vector per track (the mean over its patches): what the app searches for similar tracks.
        # The app normalises it; the sidecar only rounds, so the JSON stays about 15 kB.
        out["embedding"] = [round(float(x), 6) for x in embeddings.mean(axis=0)]
        out["embedding_model"] = "discogs-effnet-bs64-1"

        moods = []
        for mood in MOODS:
            head = f"mood_{mood}-discogs-effnet-1"
            if head in _models:
                score = _positive(head, _models[head](embeddings), mood)
                if score >= 0.5:
                    moods.append({"name": mood, "score": score})
        if moods:
            out["mood_tags"] = sorted(moods, key=lambda m: -m["score"])

        head = "danceability-discogs-effnet-1"
        if head in _models:
            # The ML score replaces the classic estimate.
            out["danceability"] = _positive(head, _models[head](embeddings), "danceable")

        head = "voice_instrumental-discogs-effnet-1"
        if head in _models:
            out["instrumental"] = _positive(head, _models[head](embeddings), "instrumental")

        head = "genre_discogs400-discogs-effnet-1"
        if head in _models:
            mean = _models[head](embeddings).mean(axis=0)
            classes = _classes(head)
            top = sorted(range(len(mean)), key=lambda i: -mean[i])[:5]
            out["genre_tags"] = [
                {"name": classes[i].split("---")[-1], "score": float(mean[i])}
                for i in top if i < len(classes) and mean[i] >= 0.1]

    if "musicnn" in _models:
        audio = es.MonoLoader(filename=path, sampleRate=16000, resampleQuality=4)()
        arousal_valence = _models["deam"](_models["musicnn"](audio)).mean(axis=0)
        # DEAM scores run 1-9.
        out["valence"] = max(0.0, min(1.0, (float(arousal_valence[0]) - 1) / 8))
        out["arousal"] = max(0.0, min(1.0, (float(arousal_valence[1]) - 1) / 8))

    return out


@app.get("/health")
def health():
    return {"ok": True, "essentia": essentia.__version__, "ml": any(k in _models for k in ("effnet", "musicnn")),
            "models": sorted(_models), "errors": _errors}


@app.post("/analyze")
def analyze(audio: UploadFile = File(...)):
    with tempfile.NamedTemporaryFile(suffix=".wav") as tmp:
        shutil.copyfileobj(audio.file, tmp)
        tmp.flush()
        with _lock:
            result = _classic(tmp.name)
            result.update(_ml(tmp.name))
    return result


def _load_16k(path):
    audio = es.MonoLoader(filename=path, sampleRate=16000, resampleQuality=4)()
    return audio, len(audio) / 16000.0


@app.post("/curve")
def mood_curve(audio: UploadFile = File(...), window: float = Form(3.0)):
    """Arousal and valence (0-1) over the track: one point per `window` seconds (1-30), the mean of the DEAM predictions of
    the MusiCNN patches that fall in it. Needs the `deam` model (503 without it)."""
    if "musicnn" not in _models or "deam" not in _models:
        raise HTTPException(status_code=503, detail="The mood models (msd-musicnn, deam) are not loaded; see /health.")
    window = max(1.0, min(30.0, window))
    with tempfile.NamedTemporaryFile(suffix=".wav") as tmp:
        shutil.copyfileobj(audio.file, tmp)
        tmp.flush()
        with _lock:
            samples, duration = _load_16k(tmp.name)
            rows = _models["deam"](_models["musicnn"](samples))
    if duration <= 0 or len(rows) == 0:
        raise HTTPException(status_code=422, detail="No audio to analyse.")
    points = curve.window_means(rows, duration, curve.PATCH_MUSICNN, window)
    # DEAM scores run 1-9; column 0 is valence, column 1 arousal (as in /analyze).
    unit = lambda v: round(max(0.0, min(1.0, (v - 1.0) / 8.0)), 3)  # noqa: E731
    return {"version": 1, "method": "deam-msd-musicnn-2", "duration": round(duration, 2), "window": window,
            "valence": [unit(p[0]) for p in points], "arousal": [unit(p[1]) for p in points]}


@app.post("/section-embeddings")
def section_embeddings(audio: UploadFile = File(...), sections: str = Form(...)):
    """The mean Discogs-EffNet embedding of each time range in `sections` (a JSON list of [start, end] seconds, at most 64):
    the sound of a section, for finding sections of other tracks that sound like it. Needs the effnet model (503 without it)."""
    if "effnet" not in _models:
        raise HTTPException(status_code=503, detail="The effnet model is not loaded; see /health.")
    try:
        ranges = [(float(a), float(b)) for a, b in json.loads(sections)]
    except (ValueError, TypeError):
        raise HTTPException(status_code=422, detail="`sections` must be a JSON list of [start, end] pairs.")
    if not ranges or len(ranges) > 64 or any(b <= a or a < 0 for a, b in ranges):
        raise HTTPException(status_code=422, detail="`sections` needs 1-64 ranges with end after start.")
    with tempfile.NamedTemporaryFile(suffix=".wav") as tmp:
        shutil.copyfileobj(audio.file, tmp)
        tmp.flush()
        with _lock:
            samples, duration = _load_16k(tmp.name)
            rows = _models["effnet"](samples)
    if duration <= 0 or len(rows) == 0:
        raise HTTPException(status_code=422, detail="No audio to analyse.")
    means = curve.range_means(rows, duration, curve.PATCH_EFFNET, ranges)
    return {"version": 1, "model": "discogs-effnet-bs64-1", "duration": round(duration, 2),
            "sections": [{"start": a, "end": b, "embedding": [round(float(x), 6) for x in m]} for (a, b), m in zip(ranges, means)]}
