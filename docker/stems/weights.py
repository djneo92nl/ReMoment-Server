"""The model weights of the stems service, downloaded ONLY when the admin asks (docs/architecture/stems-service.md, "Weights").

The Demucs weights have no clear licence, so neither the image nor the service ever fetches them by itself: the service
starts without them, says `weights: missing` in `/health` and refuses stem jobs until `POST /weights` (the app's admin
button) has downloaded them. The file lands where `torch.hub` (and so `demucs.pretrained.get_model`) looks for it,
`$TORCH_HOME/hub/checkpoints/{signature}-{checksum}.th` (the named volume `/models`), so the model then loads without
touching the network. The download is a pinned URL, checked against a pinned SHA-256 before the file gets its name.

Standard library only (no numpy, no torch): this module is imported before any of them and is tested without them.
"""

import hashlib
import os
import threading
import urllib.request
from urllib.parse import urlparse

# model => the file demucs expects in its torch.hub cache, where it comes from and what it must hash to. Both are Meta's
# own release files (demucs/remote/files.txt of demucs 4.1.0; the checksum in the name is the start of the SHA-256).
SPECS = {
    "htdemucs": {
        "file": "955717e8-8726e21a.th",
        "url": "https://dl.fbaipublicfiles.com/demucs/hybrid_transformer/955717e8-8726e21a.th",
        "sha256": "8726e21a993978c7ba086d3872e7608d7d5bfca646ca4aca459ffda844faa8b4",
        "size": 84141911,
    },
    "htdemucs_6s": {
        "file": "5c90dfd2-34c22ccb.th",
        "url": "https://dl.fbaipublicfiles.com/demucs/hybrid_transformer/5c90dfd2-34c22ccb.th",
        "sha256": "34c22ccb381c6f9fdbf324f04e1e2fe21aaaf293f5ded163a162697ff9a02ddd",
        "size": 54996327,
    },
}

MISSING, DOWNLOADING, READY, FAILED = "missing", "downloading", "ready", "failed"
CHUNK = 1 << 20


class WeightsMissing(RuntimeError):
    """The model was asked for before its weights were downloaded."""


def checkpoints_dir() -> str:
    """Where torch.hub caches a checkpoint: the same rule as `torch.hub.get_dir()`, without importing torch."""
    home = os.path.expanduser(os.environ.get("TORCH_HOME") or os.path.join(os.environ.get("XDG_CACHE_HOME") or os.path.join("~", ".cache"), "torch"))

    return os.path.join(home, "hub", "checkpoints")


class Weights:
    """The weights of one model: their state on disk and the one download that may be running.

    `STEMS_WEIGHTS_URL` / `STEMS_WEIGHTS_SHA256` replace the pinned source (a mirror, an offline copy behind an http
    server); the file name stays the one demucs expects. The SHA-256 may be given in full or as at least its first
    eight hex digits (what the file name carries)."""

    def __init__(self, model: str, directory: str | None = None, url: str | None = None, sha256: str | None = None):
        if model not in SPECS:
            raise ValueError(f"No weights known for {model!r}")
        spec = SPECS[model]
        self.model = model
        self.file = spec["file"]
        self.url = (url or os.environ.get("STEMS_WEIGHTS_URL") or spec["url"]).strip()
        self.sha256 = (sha256 or os.environ.get("STEMS_WEIGHTS_SHA256") or spec["sha256"]).strip().lower()
        if len(self.sha256) < 8 or any(c not in "0123456789abcdef" for c in self.sha256):
            raise ValueError("STEMS_WEIGHTS_SHA256 must be hex, at least 8 digits")
        self.expected_size = spec["size"] if self.url == spec["url"] else None
        self.directory = directory or checkpoints_dir()
        self.path = os.path.join(self.directory, self.file)
        self.on_ready = None  # called (no arguments) when a download finished and verified
        self._lock = threading.Lock()
        self._thread: threading.Thread | None = None
        self._downloaded = 0
        self._total: int | None = None
        self._error: str | None = None
        self._verified: tuple[int, int] | None = None  # (size, mtime_ns) of the file whose hash was checked

    # -- reading ----------------------------------------------------------------

    def _on_disk(self) -> tuple[bool, int | None]:
        """(a file with the right hash is there, its size). The hash is read once per version of the file."""
        try:
            st = os.stat(self.path)
        except OSError:
            return False, None
        key = (st.st_size, st.st_mtime_ns)
        if self._verified != key:
            if not self._hash_matches(self.path):
                return False, st.st_size
            self._verified = key

        return True, st.st_size

    def _hash_matches(self, path: str) -> bool:
        h = hashlib.sha256()
        with open(path, "rb") as f:
            while chunk := f.read(CHUNK):
                h.update(chunk)

        return h.hexdigest().startswith(self.sha256)

    def state(self) -> str:
        if self._thread is not None and self._thread.is_alive():
            return DOWNLOADING
        ok, size = self._on_disk()
        if ok:
            return READY
        if self._error is not None or size is not None:
            return FAILED

        return MISSING

    def ready(self) -> bool:
        return self.state() == READY

    def status(self) -> dict:
        """The answer of GET /weights: state missing | downloading | ready | failed, and what the admin needs to see."""
        state = self.state()
        ok, size = self._on_disk() if state != DOWNLOADING else (False, None)
        error = self._error
        if state == FAILED and error is None:
            error = "The file on disk does not match the checksum; download it again."
        total = self._total or self.expected_size

        return {
            "state": state,
            "model": self.model,
            "file": self.file,
            "size": size if ok else None,
            "downloaded": self._downloaded if state == DOWNLOADING else (size if ok else 0),
            "total": total,
            "source": self.url,
            "sha256": self.sha256,
            "error": error if state == FAILED else None,
        }

    def require(self) -> None:
        """Raises WeightsMissing (what to do about it in the message) unless the weights are downloaded."""
        state = self.state()
        if state == READY:
            return
        if state == DOWNLOADING:
            total = self._total or self.expected_size
            done = f" ({self._downloaded * 100 // total}%)" if total else ""
            raise WeightsMissing(f"weights_downloading: the stem weights are still downloading{done}")
        raise WeightsMissing("weights_missing: the stem weights are not downloaded (opt-in; the admin starts it in Settings > Audio analysis or with POST /api/admin/stems/weights)")

    # -- the download -----------------------------------------------------------

    def start(self) -> dict:
        """Starts the download in a thread unless the weights are there or arriving; answers the status either way."""
        with self._lock:
            if self.state() not in (READY, DOWNLOADING):
                scheme = urlparse(self.url).scheme
                if scheme not in ("http", "https"):
                    self._error = "The weights URL must be http or https."
                else:
                    self._error = None
                    self._downloaded = 0
                    self._total = None
                    self._thread = threading.Thread(target=self._download, daemon=True)
                    self._thread.start()

        return self.status()

    def _download(self) -> None:
        part = self.path + ".part"
        try:
            os.makedirs(self.directory, exist_ok=True)
            digest = hashlib.sha256()
            request = urllib.request.Request(self.url, headers={"User-Agent": "remoment-stems"})
            with urllib.request.urlopen(request, timeout=30) as response, open(part, "wb") as out:
                length = response.headers.get("Content-Length")
                self._total = int(length) if length and length.isdigit() else self.expected_size
                while chunk := response.read1(CHUNK):  # read1: what has arrived, so the progress moves smoothly
                    out.write(chunk)
                    digest.update(chunk)
                    self._downloaded += len(chunk)
            if self._total is not None and self._downloaded != self._total:
                raise OSError(f"the download stopped at {self._downloaded} of {self._total} bytes")
            if not digest.hexdigest().startswith(self.sha256):
                raise ValueError("the downloaded file does not match the pinned SHA-256 and was thrown away")
            os.replace(part, self.path)
            self._verified = None  # the next state() hashes the final file once more: cheap, and what really sits there
            self._error = None
        except BaseException as e:  # noqa: BLE001 - offline, disk full, a changed file at the source ...
            self._error = (str(e).splitlines()[0] if str(e) else e.__class__.__name__)[:200]
            try:
                os.remove(part)
            except OSError:
                pass

            return
        if self.on_ready is not None:
            try:
                self.on_ready()
            except Exception:  # noqa: BLE001 - a failing preload must not undo the download
                pass
