"""The pipeline and the maths, not the model: the separator is replaced by a deterministic band split (bass under
200 Hz, vocals 200-3000 Hz, drums above 3000 Hz, other silent) on a synthetic mixture of known tones, so the window
stitching, the dB frames, the instrumental ranges and the API are tested without PyTorch weights.
Run: see requirements-dev.txt. STEMS_REAL_MODEL=1 additionally runs the real htdemucs once (needs the weights)."""
import os
import sys
import wave

import numpy as np
import pytest

os.environ["STEMS_PRELOAD"] = "0"
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from fastapi.testclient import TestClient  # noqa: E402

import app as service  # noqa: E402
import separate  # noqa: E402

SR = separate.SR


def band_split(block):
    """(n, 2) -> (4, n): ideal FFT band split of the mono mix, standing in for the model."""
    mono = block.mean(1)
    spec = np.fft.rfft(mono)
    f = np.fft.rfftfreq(len(mono), 1 / SR)
    out = []
    for lo, hi in ((200, 3000), (3000, SR), (0, 200)):  # vocals, drums, bass
        out.append(np.fft.irfft(np.where((f >= lo) & (f < hi), spec, 0), len(mono)))
    out.append(np.zeros(len(mono)))  # other
    return np.array(out)


@pytest.fixture(autouse=True)
def fake_model(monkeypatch):
    monkeypatch.setattr(separate, "separate_block", band_split)
    monkeypatch.setattr(separate, "_model", object())


def mixture(seconds=60, vocal=(20, 40)):
    """Bass tone all along, a 440 Hz 'voice' only in `vocal` (seconds), hi-hat noise bursts every half second."""
    rng = np.random.default_rng(3)
    t = np.arange(int(seconds * SR)) / SR
    y = 0.3 * np.sin(2 * np.pi * 80 * t)
    v = 0.3 * np.sin(2 * np.pi * 440 * t + 2 * np.sin(2 * np.pi * 5 * t))
    y += np.where((t >= vocal[0]) & (t < vocal[1]), v, 0)
    hat = np.zeros_like(t)
    for k in range(int(seconds * 2)):
        a = int(k * 0.5 * SR)
        hat[a : a + 2000] = rng.standard_normal(2000) * np.exp(-np.arange(2000) / 300) * 0.2
    y += np.convolve(hat, [1, -1], "same")  # first difference: pushes the energy above 3 kHz
    return np.clip(y, -1, 1)


def write_wav(path, mono):
    pcm = (np.stack([mono, mono], 1) * 32767).astype("<i2")
    with wave.open(str(path), "wb") as w:
        w.setnchannels(2)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(pcm.tobytes())


@pytest.fixture
def wav(tmp_path):
    p = tmp_path / "mix.wav"
    write_wav(p, mixture())
    return p


def mean(curve, a, b):
    return float(np.mean(curve[int(a * 2) : int(b * 2)]))


def test_lanes_follow_the_known_tones(wav):
    duration, stems = separate.curves(str(wav), window=10 * SR, pad=SR)
    assert duration == pytest.approx(60, abs=0.1)
    assert {k: len(v) for k, v in stems.items()} == {k: 120 for k in separate.SOURCES}
    assert mean(stems["vocals"], 22, 38) > mean(stems["vocals"], 2, 18) + 40  # the voice is only in the middle
    assert mean(stems["bass"], 2, 58) > -25 and abs(mean(stems["bass"], 2, 18) - mean(stems["bass"], 22, 38)) < 2  # steady
    assert mean(stems["other"], 0, 60) == separate.FLOOR  # silent lane sits on the floor
    assert max(max(v) for v in stems.values()) <= separate.CEIL


def test_windowed_curves_equal_the_whole_track(wav):
    _, whole = separate.curves(str(wav), window=60 * SR, pad=SR)
    _, windows = separate.curves(str(wav), window=10 * SR, pad=2 * SR)
    for name in separate.SOURCES:
        diff = np.abs(np.array(whole[name]) - np.array(windows[name]))
        assert diff.max() <= 3, name  # the band split rings a little at window edges; the stitching has no offsets or gaps


def test_instrumental_ranges_and_vocal_ratio(wav):
    result = separate.analyze(str(wav))
    assert result["instrumental"] == [{"start": pytest.approx(0, abs=0.5), "end": pytest.approx(20, abs=1.5)}, {"start": pytest.approx(40, abs=1.5), "end": pytest.approx(60, abs=0.5)}]
    assert result["vocal_ratio"] == pytest.approx(1 / 3, abs=0.06)
    assert result["rate_hz"] == 2 and result["method"] == "htdemucs" and result["version"] == 1


def test_instrumental_maths_on_curves():
    quiet, loud = -70, -20
    assert separate.instrumental([loud] * 100) == (1.0, [])  # always vocal
    ratio, ranges = separate.instrumental([quiet] * 100)  # no vocals at all: the whole track
    assert ratio == 0.0 and ranges == [{"start": 0.0, "end": 50.0}]
    # a quiet run shorter than MIN_LEN is not instrumental, a longer one is
    short = [loud] * 40 + [quiet] * 12 + [loud] * 40  # 6 s
    assert separate.instrumental(short)[1] == []
    longer = [loud] * 40 + [quiet] * 24 + [loud] * 40  # 12 s
    assert separate.instrumental(longer)[1] == [{"start": 20.0, "end": 32.0}]
    # a vocal fragment of 2 s inside a long instrumental passage does not split it
    split = [loud] * 20 + [quiet] * 20 + [loud] * 4 + [quiet] * 20 + [loud] * 20
    assert separate.instrumental(split)[1] == [{"start": 10.0, "end": 32.0}]
    assert separate.instrumental([]) == (0.0, [])


def test_frames_db_is_integer_and_clamped():
    loud = np.ones(separate.HOP * 2)  # 0 dBFS
    silent = np.zeros(separate.HOP)
    mono = np.concatenate([loud * 4, silent, np.ones(separate.HOP) * 0.1, np.ones(100)])  # +12 dB (clamped), silence, -20 dB, partial frame dropped
    assert separate.frames_db(mono) == [0, 0, -80, -20]
    assert separate.frames_db(np.zeros(10)) == []


def test_api_returns_the_contract(wav):
    client = TestClient(service.app)
    assert client.get("/health").json()["ok"] is True
    with open(wav, "rb") as f:
        r = client.post("/stems", files={"audio": ("mix.wav", f, "audio/wav")})
    assert r.status_code == 200
    body = r.json()
    assert set(body) == {"version", "duration", "method", "rate_hz", "stems", "vocal_ratio", "instrumental"}
    assert all(isinstance(x, int) for x in body["stems"]["vocals"])


def test_too_long_is_413(wav, monkeypatch):
    monkeypatch.setattr(service, "MAX_DURATION", 30)
    with open(wav, "rb") as f:
        r = TestClient(service.app).post("/stems", files={"audio": ("mix.wav", f)})
    assert r.status_code == 413 and "STEMS_MAX_DURATION" in r.json()["detail"]


def test_not_audio_is_422():
    r = TestClient(service.app).post("/stems", files={"audio": ("x.txt", b"hello", "text/plain")})
    assert r.status_code == 422


def test_model_unavailable_is_503_and_health_says_why(wav, monkeypatch):
    monkeypatch.setattr(separate, "_model", None)
    monkeypatch.setattr(service, "_load_error", None)

    def fail():
        raise RuntimeError("offline")

    monkeypatch.setattr(separate, "load_model", fail)
    client = TestClient(service.app)
    with open(wav, "rb") as f:
        r = client.post("/stems", files={"audio": ("mix.wav", f)})
    assert r.status_code == 503 and "offline" in r.json()["detail"]
    h = client.get("/health").json()
    assert h["ok"] is False and h["error"] == "offline"


@pytest.mark.skipif(os.environ.get("STEMS_REAL_MODEL") != "1", reason="needs the htdemucs weights (STEMS_REAL_MODEL=1)")
def test_real_model_runs_the_pipeline(tmp_path, monkeypatch):
    monkeypatch.undo()  # drop the fake separator and model
    p = tmp_path / "mix.wav"
    write_wav(p, mixture(seconds=20, vocal=(5, 15)))
    result = separate.analyze(str(p))
    assert len(result["stems"]["vocals"]) == 40 and set(result["stems"]) == set(separate.SOURCES)
