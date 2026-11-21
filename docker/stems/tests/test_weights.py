"""The opt-in weights download (weights.py): standard library only, a local http server stands in for the source.
Run with the others (requirements-dev.txt)."""
import hashlib
import http.server
import os
import sys
import threading
import time

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import weights  # noqa: E402

PAYLOAD = os.urandom(300_000)
DIGEST = hashlib.sha256(PAYLOAD).hexdigest()


class Source:
    """Serves PAYLOAD (or `body`) at /w.th; `gate` holds the response until set, to watch a download under way."""

    def __init__(self, body=PAYLOAD, gate=None):
        source = self
        self.hits = 0
        self.gate = gate

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):  # noqa: N802
                source.hits += 1
                self.send_response(200)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body[:100_000])
                self.wfile.flush()
                if source.gate:
                    source.gate.wait(5)
                self.wfile.write(body[100_000:])

            def log_message(self, *a):
                pass

        self.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.url = f"http://127.0.0.1:{self.server.server_port}/w.th"
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def close(self):
        self.server.shutdown()


def wait_for(w, states, seconds=5):
    end = time.time() + seconds
    while time.time() < end:
        if w.state() in states:
            return
        time.sleep(0.02)
    raise AssertionError(f"still {w.state()}: {w.status()}")


def make(tmp_path, url, sha=DIGEST):
    return weights.Weights("htdemucs", directory=str(tmp_path / "hub" / "checkpoints"), url=url, sha256=sha)


def test_pinned_sources_match_the_demucs_file_names():
    for model, spec in weights.SPECS.items():
        checksum = spec["file"].rsplit("-", 1)[1].removesuffix(".th")  # the name carries the start of the sha256
        assert spec["sha256"].startswith(checksum) and len(spec["sha256"]) == 64
        assert spec["url"].endswith(spec["file"]) and spec["url"].startswith("https://")


def test_checkpoints_dir_follows_torch_home(monkeypatch):
    monkeypatch.setenv("TORCH_HOME", "/models")
    assert weights.checkpoints_dir() == "/models/hub/checkpoints"


def test_a_fresh_directory_is_missing_and_require_says_what_to_do(tmp_path):
    w = make(tmp_path, "http://127.0.0.1:9/w.th")
    s = w.status()
    assert s["state"] == "missing" and s["size"] is None and s["error"] is None
    with pytest.raises(weights.WeightsMissing, match="weights_missing"):
        w.require()


def test_download_verifies_and_names_the_file(tmp_path):
    src = Source()
    try:
        w = make(tmp_path, src.url)
        ready = []
        w.on_ready = lambda: ready.append(1)
        w.start()
        wait_for(w, ("ready", "failed"))
        s = w.status()
        assert s["state"] == "ready" and s["size"] == len(PAYLOAD) and s["error"] is None
        assert open(w.path, "rb").read() == PAYLOAD and not os.path.exists(w.path + ".part")
        w.require()
        time.sleep(0.1)
        assert ready == [1]
        # asking again neither downloads again nor fails
        assert w.start()["state"] == "ready" and src.hits == 1
    finally:
        src.close()


def test_progress_is_readable_while_downloading_and_start_is_idempotent(tmp_path):
    gate = threading.Event()
    src = Source(gate=gate)
    try:
        w = make(tmp_path, src.url)
        w.start()
        end = time.time() + 5
        while w.status()["downloaded"] < 100_000 and time.time() < end:
            time.sleep(0.02)
        s = w.status()
        assert s["state"] == "downloading" and s["downloaded"] >= 100_000 and s["total"] == len(PAYLOAD)
        assert w.start()["state"] == "downloading" and src.hits == 1  # no second download
        with pytest.raises(weights.WeightsMissing, match="weights_downloading"):
            w.require()
        gate.set()
        wait_for(w, ("ready", "failed"))
        assert w.state() == "ready"
    finally:
        gate.set()
        src.close()


def test_a_wrong_checksum_throws_the_file_away(tmp_path):
    src = Source()
    try:
        w = make(tmp_path, src.url, sha="0" * 64)
        w.start()
        wait_for(w, ("failed",))
        s = w.status()
        assert s["state"] == "failed" and "SHA-256" in s["error"]
        assert not os.path.exists(w.path) and not os.path.exists(w.path + ".part")
        with pytest.raises(weights.WeightsMissing):
            w.require()
    finally:
        src.close()


def test_an_unreachable_source_fails_with_the_reason_and_can_be_retried(tmp_path):
    w = make(tmp_path, "http://127.0.0.1:9/w.th")
    w.start()
    wait_for(w, ("failed",))
    assert w.status()["error"]
    src = Source()
    try:
        w.url = src.url
        w.start()
        wait_for(w, ("ready", "failed"))
        assert w.state() == "ready" and w.status()["error"] is None
    finally:
        src.close()


def test_a_corrupt_file_on_disk_is_not_ready(tmp_path):
    w = make(tmp_path, "http://127.0.0.1:9/w.th")
    os.makedirs(w.directory)
    open(w.path, "wb").write(b"truncated")
    s = w.status()
    assert s["state"] == "failed" and "checksum" in s["error"]


def test_weights_already_on_the_volume_count_as_ready_without_a_download(tmp_path):
    w = make(tmp_path, "http://127.0.0.1:9/w.th")
    os.makedirs(w.directory)
    open(w.path, "wb").write(PAYLOAD)
    assert w.status()["state"] == "ready" and w.status()["size"] == len(PAYLOAD)


def test_only_http_sources_are_accepted(tmp_path):
    w = make(tmp_path, "file:///etc/passwd")
    s = w.start()
    assert s["state"] == "failed" and "http" in s["error"]


def test_the_checksum_setting_must_be_hex(tmp_path):
    with pytest.raises(ValueError):
        weights.Weights("htdemucs", directory=str(tmp_path), sha256="nothex!!")
