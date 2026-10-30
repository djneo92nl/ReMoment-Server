"""Synthetic pieces with known cuts. Run: see requirements-dev.txt."""
import io
import os
import sys

import numpy as np
import pytest
import soundfile

os.environ["STRUCTURE_WARMUP"] = "0"
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from fastapi.testclient import TestClient  # noqa: E402

import app as service  # noqa: E402

SR = 22050
rng = np.random.default_rng(7)


def part(seconds, seed, notes, harmonics, drum, bpm=120):
    """Eighth-note pattern (repeats every bar) with a drum kind and a little noise, like a looped song part."""
    rng = np.random.default_rng(seed)
    n = int(SR * seconds)
    y = np.zeros(n)
    step = 60 / bpm / 2
    pat = rng.choice(notes, 8)
    for k in range(int(seconds / step)):
        a = int(k * step * SR)
        e = min(n, a + int(step * SR))
        if e <= a:
            continue
        tt = np.arange(e - a) / SR
        f = 440 * 2 ** ((pat[k % 8] - 69) / 12)
        y[a:e] += sum(np.sin(2 * np.pi * f * h * tt) / h for h in range(1, harmonics + 1)) * np.exp(-6 * tt) * 0.3
        if k % 2 == 0 and drum == "kick":
            y[a:e] += np.sin(2 * np.pi * 55 * tt) * np.exp(-20 * tt) * 1.5
        if drum == "hat":
            y[a:e] += (0.5 if k % 2 == 0 else 0.3) * rng.standard_normal(e - a) * np.exp(-70 * tt)
    y += 0.05 * rng.standard_normal(n) * np.convolve(np.abs(np.sin(np.arange(n) / 4000.0)), np.ones(50) / 50, "same")
    return (y / np.abs(y).max() * 0.5).astype(np.float32)


def noise_section(seconds, cutoff):
    n = rng.standard_normal(int(SR * seconds))
    spec = np.fft.rfft(n)
    spec[int(len(spec) * cutoff):] = 0
    y = np.fft.irfft(spec, len(n)).astype(np.float32)
    return y / (np.abs(y).max() + 1e-9) * 0.4


def wav(y):
    buf = io.BytesIO()
    soundfile.write(buf, y, SR, format="WAV", subtype="PCM_16")
    buf.seek(0)
    return ("piece.wav", buf, "audio/wav")


@pytest.fixture
def client():
    return TestClient(service.app)


def post(client, y, **data):
    return client.post("/structure", files={"audio": wav(y)}, data=data)


def test_health(client):
    r = client.get("/health").json()
    assert r["ok"] is True and r["method"] == "novelty-v1" and r["version"] == 1


def test_known_cuts_and_repeated_texture(client):
    a = part(32, 1, [57, 60, 64, 69], 6, "kick")
    b = part(32, 2, [71, 74, 78, 83, 86], 2, "hat")
    c = part(32, 3, [45, 48, 52, 55], 10, "kick")
    y = np.concatenate([a, b, c, a])  # A B C A, 128 s
    body = post(client, y).json()
    assert body["version"] == 1 and body["method"] == "novelty-v1" and "reason" not in body
    assert body["duration"] == pytest.approx(128, abs=0.1)
    sections = body["sections"]
    assert sections[0]["start"] == 0.0 and sections[-1]["end"] == pytest.approx(128, abs=0.1)
    cuts = [s["start"] for s in sections[1:]]
    for want in (32, 64, 96):
        assert min(abs(c - want) for c in cuts) <= 4, (want, cuts)
    for s in sections:
        assert set(s) == {"start", "end", "label", "confidence"}
        assert s["label"] is None or len(s["label"]) == 1
    # the repeated first texture gets the first letter again
    assert sections[0]["label"] == sections[-1]["label"]
    assert len({s["label"] for s in sections}) >= 3


def test_too_short(client):
    body = post(client, part(20, 1, [57, 60], 3, "kick")).json()
    assert body["sections"] == [] and body["reason"] == "too_short"


def test_silence(client):
    body = post(client, np.zeros(SR * 60, dtype=np.float32)).json()
    assert body["sections"] == [] and body["reason"] == "no_structure"


def test_steady_noise_has_no_structure(client):
    body = post(client, noise_section(90, 1.0)).json()
    assert body["sections"] == [] and body["reason"] == "no_structure"


def test_duration_limit_answers_413(client, monkeypatch):
    monkeypatch.setattr(service, "MAX_DURATION", 40)
    r = post(client, part(60, 1, [57, 60], 3, "kick"))
    assert r.status_code == 413 and "at most" in r.json()["detail"]


def test_unknown_method_and_bad_audio(client):
    assert post(client, part(40, 1, [57], 3, "kick"), method="allin1").status_code == 422
    r = client.post("/structure", files={"audio": ("x.wav", b"not audio", "audio/wav")})
    assert r.status_code == 422
