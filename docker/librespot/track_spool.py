"""One MP3 per Spotify track, served over HTTP while it is still being encoded.

The endless Icecast stream carries one title and no per-track art, and a UPnP renderer reads its art from the
metadata of the item it plays. This cuts the PCM at the track boundaries librespot's --onevent hook reports and turns
every track into its own item: an MP3 with an ID3 tag and the cover embedded, at /tracks/N.mp3, with the cover itself
at /tracks/N.jpg (a small baseline JPEG over plain http, for renderers that cannot fetch https).

Used by pcm-filler.py: it calls feed() with every real or silent PCM chunk, switch() at a boundary, and the HTTP
server's /_event endpoint (called by hook.sh from the same container) creates the track an event announces.

A track's response is close-delimited (HTTP/1.0, like Icecast) and starts at the track's first byte whenever the
renderer asks, so a renderer that fetches the next item only when the previous one has drained its buffer still gets
the whole track. It ends when the next track begins.
"""
import json
import os
import queue
import shutil
import subprocess
import tempfile
import threading
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

RATE = 44100


def _frame(frame_id, payload):
    return frame_id.encode() + len(payload).to_bytes(4, "big") + b"\x00\x00" + payload


def _text(frame_id, value):
    # ID3v2.3 text: encoding byte 1 = UTF-16 with a byte order mark.
    return _frame(frame_id, b"\x01\xff\xfe" + value.encode("utf-16-le") + b"\x00\x00")


def id3v2(title, artist, album, duration_ms=None, cover=None):
    """An ID3v2.3 tag with title, artist, album, duration and the cover as the front-cover picture.

    Written by hand because ffmpeg's MP3 muxer drops the whole tag, picture included, when its output is a pipe.
    """
    frames = _text("TIT2", title) + _text("TPE1", artist) + _text("TALB", album)

    if duration_ms:
        frames += _frame("TLEN", b"\x00" + str(int(duration_ms)).encode() + b"\x00")

    if cover:
        frames += _frame("APIC", b"\x00image/jpeg\x00\x03\x00" + cover)

    size = len(frames)
    synchsafe = bytes([(size >> 21) & 0x7F, (size >> 14) & 0x7F, (size >> 7) & 0x7F, size & 0x7F])

    return b"ID3\x03\x00\x00" + synchsafe + frames


class Track:
    def __init__(self, number, meta, spool_dir, bitrate, encoder_cmd):
        self.number = number
        self.meta = meta
        self.mp3_path = os.path.join(spool_dir, f"{number}.mp3")
        self.cover_path = os.path.join(spool_dir, f"{number}.jpg")
        self.has_cover = False
        self.done = False
        self.failed = False
        self._bitrate = bitrate
        self._encoder_cmd = encoder_cmd
        self._pcm = queue.Queue()
        self._cond = threading.Condition()
        self._written = 0
        self.pcm_bytes = 0
        # The cover download and the encoder start must not hold the audio loop up: PCM queues until they are ready.
        threading.Thread(target=self._run, daemon=True, name=f"track-{number}").start()

    # --- audio side -------------------------------------------------------------------------------------------

    def feed(self, data):
        self.pcm_bytes += len(data)
        self._pcm.put(data)

    def finish(self):
        """No more PCM: the encoder is drained and the response ends."""
        self._pcm.put(None)

    # --- encoder ----------------------------------------------------------------------------------------------

    def _download_cover(self):
        covers = [c for c in (self.meta.get("covers") or "").splitlines() if c.strip()]

        if not covers:
            return

        try:
            # librespot lists the covers smallest first; the largest is cut down to a small baseline JPEG below.
            with urllib.request.urlopen(urllib.request.Request(covers[-1], headers={"User-Agent": "ReMoment/1.0"}), timeout=5) as r:
                raw = r.read(4_000_000)
            source = self.cover_path + ".src"
            with open(source, "wb") as f:
                f.write(raw)
            # Baseline (non-progressive) JPEG, 320 px: small enough for old devices, readable by every decoder.
            subprocess.run(
                [self._encoder_cmd, "-hide_banner", "-loglevel", "error", "-y", "-i", source, "-vf", "scale=320:320",
                 "-q:v", "3", "-pix_fmt", "yuvj420p", "-f", "image2", "-c:v", "mjpeg", self.cover_path],
                check=True, timeout=15,
            )
            os.remove(source)
            self.has_cover = os.path.getsize(self.cover_path) > 0
        except Exception as e:  # a missing cover must never cost the track
            print(f"track {self.number}: no cover ({e})", flush=True)
            self.has_cover = False

    def _args(self):
        # No tags from ffmpeg: its MP3 muxer cannot write them to a pipe, so _read() puts ours in front.
        return [self._encoder_cmd, "-hide_banner", "-loglevel", "error", "-f", "s16le", "-ar", str(RATE), "-ac", "2", "-i", "pipe:0",
                "-c:a", "libmp3lame", "-b:a", f"{self._bitrate}k", "-write_id3v2", "0", "-write_xing", "0", "-f", "mp3", "pipe:1"]

    def _tag(self):
        m = self.meta
        artists = (m.get("artists") or "").splitlines()
        cover = None

        if self.has_cover:
            with open(self.cover_path, "rb") as f:
                cover = f.read()

        try:
            duration = int(m.get("duration_ms") or 0)
        except ValueError:
            duration = 0

        return id3v2(m.get("name", ""), artists[0] if artists else "", m.get("album", ""), duration, cover)

    def _run(self):
        try:
            self._download_cover()
            encoder = subprocess.Popen(self._args(), stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
            reader = threading.Thread(target=self._read, args=(encoder,), daemon=True)
            reader.start()

            while True:
                data = self._pcm.get()
                if data is None:
                    break
                try:
                    encoder.stdin.write(data)
                except (BrokenPipeError, OSError):
                    break

            try:
                encoder.stdin.close()
            except OSError:
                pass
            reader.join(timeout=30)
            encoder.wait(timeout=10)
        except Exception as e:
            print(f"track {self.number}: encoder failed ({e})", flush=True)
            self.failed = True
        finally:
            with self._cond:
                self.done = True
                self._cond.notify_all()

    def _read(self, encoder):
        try:
            out = open(self.mp3_path, "wb")
        except OSError:  # the spool was removed under it (shutdown, or the track was dropped as too old)
            self.failed = True
            return

        with out:
            tag = self._tag()
            out.write(tag)
            out.flush()
            with self._cond:
                self._written += len(tag)
                self._cond.notify_all()

            while True:
                chunk = encoder.stdout.read(4096)
                if not chunk:
                    break
                out.write(chunk)
                out.flush()
                with self._cond:
                    self._written += len(chunk)
                    self._cond.notify_all()

    # --- http side --------------------------------------------------------------------------------------------

    def stream(self, write, alive=lambda: True):
        """Hand the MP3 to `write` from its first byte, following the encoder until the track is done."""
        sent = 0
        deadline_for_file = 10

        while not os.path.exists(self.mp3_path) and deadline_for_file > 0 and not self.done:
            with self._cond:
                self._cond.wait(0.2)
            deadline_for_file -= 0.2

        if not os.path.exists(self.mp3_path):
            return

        with open(self.mp3_path, "rb") as f:
            while alive():
                with self._cond:
                    available = self._written - sent
                    finished = self.done
                    if available <= 0 and not finished:
                        self._cond.wait(1.0)
                        continue
                data = f.read(65536)
                if data:
                    write(data)
                    sent += len(data)
                elif finished:
                    return

    def remove(self):
        for path in (self.mp3_path, self.cover_path):
            try:
                os.remove(path)
            except OSError:
                pass


class TrackSpool:
    def __init__(self, port, bitrate=320, keep=4, encoder_cmd="ffmpeg", spool_dir=None, bind="0.0.0.0"):
        self.port = port
        self.bitrate = bitrate
        self.keep = keep
        self.encoder_cmd = encoder_cmd
        self.spool_dir = spool_dir or tempfile.mkdtemp(prefix="tracks-")
        os.makedirs(self.spool_dir, exist_ok=True)
        self.tracks = {}
        self.counter = 0
        self.current = None
        self._lock = threading.Lock()
        self.on_event = None  # set by the filler: fields -> dict (it knows the stream position)
        self._server = ThreadingHTTPServer((bind, port), self._handler())
        self._server.daemon_threads = True
        self._serving = False

    def serve_in_background(self):
        self._serving = True
        threading.Thread(target=self._server.serve_forever, daemon=True, name="track-http").start()

    def close(self):
        if self._serving:
            self._server.shutdown()
        self._server.server_close()
        shutil.rmtree(self.spool_dir, ignore_errors=True)

    # --- tracks -----------------------------------------------------------------------------------------------

    def create(self, meta):
        """A track the hook announced. It starts receiving PCM when the stream reaches its boundary (switch())."""
        with self._lock:
            self.counter += 1
            track = Track(self.counter, meta, self.spool_dir, self.bitrate, self.encoder_cmd)
            self.tracks[track.number] = track
            for old in sorted(self.tracks)[:-self.keep]:
                self.tracks.pop(old).remove()

        return track

    def switch(self, track):
        """The stream reached the boundary: the previous track ends, this one begins."""
        if self.current is not None:
            self.current.finish()
        self.current = track

    def feed(self, data):
        if self.current is not None and data:
            self.current.feed(data)

    def end(self, wait=0):
        """The stream is over: finish the current track and give a renderer mid-way up to `wait` s to receive its end."""
        track, self.current = self.current, None

        if track is None:
            return

        track.finish()

        with track._cond:
            deadline = time.monotonic() + wait
            while wait and not track.done and time.monotonic() < deadline:
                track._cond.wait(0.2)

    # --- http -------------------------------------------------------------------------------------------------

    def _handler(self):
        spool = self

        class Handler(BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.0"  # close-delimited, like Icecast: every renderer copes with it

            def log_message(self, *args):
                pass

            def _track(self, name):
                number = name.rsplit(".", 1)[0]
                return spool.tracks.get(int(number)) if number.isdigit() else None

            def do_HEAD(self):
                self._serve(head=True)

            def do_GET(self):
                self._serve(head=False)

            def _serve(self, head):
                if not self.path.startswith("/tracks/"):
                    return self.send_error(404)

                name = self.path[len("/tracks/"):].split("?")[0]
                track = self._track(name)

                if track is None:
                    return self.send_error(404)

                if name.endswith(".jpg"):
                    return self._cover(track, head)

                self.send_response(200)
                self.send_header("Content-Type", "audio/mpeg")
                self.send_header("Cache-Control", "no-cache")
                self.send_header("transferMode.dlna.org", "Streaming")
                self.end_headers()

                if head:
                    return

                try:
                    track.stream(self.wfile.write)
                    self.wfile.flush()
                except (BrokenPipeError, ConnectionResetError, OSError):
                    pass

            def _cover(self, track, head):
                # The cover is fetched and cut down in the background; a renderer asking early waits a moment for it.
                for _ in range(20):
                    if track.has_cover or track.done:
                        break
                    threading.Event().wait(0.1)

                if not track.has_cover:
                    return self.send_error(404)

                with open(track.cover_path, "rb") as f:
                    data = f.read()

                self.send_response(200)
                self.send_header("Content-Type", "image/jpeg")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()

                if not head:
                    self.wfile.write(data)

            def do_POST(self):
                if self.path != "/_event" or self.client_address[0] not in ("127.0.0.1", "::1"):
                    return self.send_error(404)

                length = int(self.headers.get("Content-Length") or 0)
                body = self.rfile.read(length).decode("utf-8", "replace")
                fields = {}
                for pair in body.split("&"):
                    if "=" in pair:
                        from urllib.parse import unquote_plus
                        key, value = pair.split("=", 1)
                        fields[unquote_plus(key)] = unquote_plus(value)

                answer = spool.on_event(fields) if spool.on_event else {}
                payload = json.dumps(answer).encode()

                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

        return Handler
