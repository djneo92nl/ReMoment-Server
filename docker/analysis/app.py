"""Essentia analysis sidecar: POST /analyze (a mono WAV) answers BPM, key, danceability and, when the
TensorFlow models are installed, mood, arousal/valence, voice/instrumental and genre. GET /health says
what is available. The Laravel app (App\\Domain\\Analysis\\Stages\\EssentiaStage) is the only client."""

import json
import os
import shutil
import tempfile
import threading

import essentia
import essentia.standard as es
from fastapi import FastAPI, File, UploadFile

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


def _load_models():
    """Loads whatever models are present; a missing one is skipped, not fatal."""
    if not WANT_ML:
        return
    try:
        from essentia.standard import (TensorflowPredict2D, TensorflowPredictEffnetDiscogs,
                                       TensorflowPredictMusiCNN)
    except ImportError:
        return

    if os.path.exists(_path("discogs-effnet-bs64-1.pb")):
        _models["effnet"] = TensorflowPredictEffnetDiscogs(
            graphFilename=_path("discogs-effnet-bs64-1.pb"), output="PartitionedCall:1")
        heads = [f"mood_{m}-discogs-effnet-1" for m in MOODS] + [
            "danceability-discogs-effnet-1", "voice_instrumental-discogs-effnet-1", "genre_discogs400-discogs-effnet-1"]
        for head in heads:
            if os.path.exists(_path(head + ".pb")):
                _models[head] = TensorflowPredict2D(
                    graphFilename=_path(head + ".pb"),
                    input="serving_default_model_Placeholder", output="PartitionedCall:0")

    if os.path.exists(_path("msd-musicnn-1.pb")) and os.path.exists(_path("deam-msd-musicnn-2.pb")):
        _models["musicnn"] = TensorflowPredictMusiCNN(
            graphFilename=_path("msd-musicnn-1.pb"), output="model/dense/BiasAdd")
        _models["deam"] = TensorflowPredict2D(
            graphFilename=_path("deam-msd-musicnn-2.pb"), input="model/Placeholder", output="model/Identity")


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
            "models": sorted(_models)}


@app.post("/analyze")
def analyze(audio: UploadFile = File(...)):
    with tempfile.NamedTemporaryFile(suffix=".wav") as tmp:
        shutil.copyfileobj(audio.file, tmp)
        tmp.flush()
        with _lock:
            result = _classic(tmp.name)
            result.update(_ml(tmp.name))
    return result
