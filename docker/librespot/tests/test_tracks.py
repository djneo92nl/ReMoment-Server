"""Run: python3 -m unittest discover -s docker/librespot/tests (needs ffmpeg and ffprobe).

Not part of `composer test`: it tests the librespot container's Python, which has no PHP in it.
"""
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import urllib.request
from http.server import BaseHTTPRequestHandler, HTTPServer

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))

import track_spool  # noqa: E402

spec = importlib.util.spec_from_file_location("pcm_filler", os.path.join(os.path.dirname(HERE), "pcm-filler.py"))
pcm_filler = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pcm_filler)

SECOND = pcm_filler.RATE * pcm_filler.FRAME


class FakeSpool:
    """Records which bytes reach which track instead of encoding them."""

    def __init__(self):
        self.tracks, self.current, self.counter, self.on_event = {}, None, 0, None

    def create(self, meta):
        self.counter += 1
        track = type("T", (), {"number": self.counter, "data": bytearray()})()
        self.tracks[track.number] = track
        return track

    def switch(self, track):
        self.current = track

    def feed(self, data):
        if self.current is not None:
            self.current.data += data

    def end(self):
        pass


class SplitterTest(unittest.TestCase):
    def test_pipe_pending_counts_the_unread_bytes(self):
        r, w = os.pipe()
        os.write(w, b"x" * 1234)
        self.assertEqual(1234, pcm_filler.pipe_pending(r))
        os.read(r, 234)
        self.assertEqual(1000, pcm_filler.pipe_pending(r))

    def test_the_boundary_is_exact_even_with_audio_still_in_the_pipe(self):
        r, w = os.pipe()
        spool = FakeSpool()
        splitter = pcm_filler.Splitter(spool, r)
        chunk = pcm_filler.CHUNK

        # Track 1 is already announced and starts at stream position 0; its first 2.5 chunks were read and handed on.
        os.write(w, b"\x01" * (chunk * 5 // 2 // 4 * 4))
        got = os.read(r, chunk * 5 // 2 // 4 * 4)
        splitter.read(len(got))
        first = spool.create({})
        splitter.pending.append((0, first))
        pending_buffer = bytearray(got)

        def step(real=True):
            data = bytes(pending_buffer[:chunk]) if real else bytes(chunk)
            del pending_buffer[:chunk]
            splitter.route(data, real)

        step(); step()

        # Track 2 is announced while 1.4 chunks of track 1 are still unread in the pipe: its boundary lies after them.
        tail = (chunk * 7 // 5) // 4 * 4
        os.write(w, b"\x01" * tail)
        before = splitter.real_in
        track2 = splitter.on_event({"event": "track_changed", "name": "Two"})
        self.assertEqual(2, track2["item"])
        boundary = splitter.pending[-1][0]
        self.assertEqual(before + tail, boundary)

        # Everything read after that (the rest of track 1, then track 2's own audio) is routed by position.
        rest = os.read(r, tail)
        splitter.read(len(rest))
        pending_buffer += rest
        os.write(w, b"\x02" * (chunk * 3))
        more = os.read(r, chunk * 3)
        splitter.read(len(more))
        pending_buffer += more

        while len(pending_buffer) >= chunk:
            step()
        step(real=False)  # a silent tick: it belongs to the track that is current

        one, two = bytes(spool.tracks[1].data), bytes(spool.tracks[2].data)
        self.assertEqual({1}, set(one[: len(got) + tail]), "track 1 keeps all of its own audio")
        self.assertNotIn(2, set(one), "none of track 2 leaks into track 1")
        self.assertNotIn(1, set(two), "none of track 1 leaks into track 2")
        self.assertEqual(len(got) + tail, boundary)

    def test_a_boundary_in_silence_starts_the_track_at_the_next_tick(self):
        r, _ = os.pipe()
        spool = FakeSpool()
        splitter = pcm_filler.Splitter(spool, r)
        item = splitter.on_event({"event": "track_changed", "name": "Idle start"})
        self.assertEqual(1, item["item"])

        splitter.route(bytes(pcm_filler.CHUNK), real=False)
        self.assertIs(spool.tracks[1], spool.current)

    def test_other_events_are_not_tracks(self):
        r, _ = os.pipe()
        splitter = pcm_filler.Splitter(FakeSpool(), r)
        self.assertEqual({}, splitter.on_event({"event": "playing"}))
        self.assertEqual({}, splitter.on_event({"event": "track_changed", "name": "  "}))


class CoverServer:
    def __init__(self, jpeg):
        body = jpeg

        class H(BaseHTTPRequestHandler):
            def do_GET(self):
                self.send_response(200)
                self.send_header("Content-Type", "image/jpeg")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *a):
                pass

        self.server = HTTPServer(("127.0.0.1", 0), H)
        self.url = f"http://127.0.0.1:{self.server.server_port}/cover.jpg"
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def close(self):
        self.server.shutdown()


def probe(path):
    out = subprocess.run(["ffprobe", "-v", "error", "-show_format", "-show_streams", "-of", "json", path], capture_output=True, text=True, check=True).stdout
    return json.loads(out)


class SpoolTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp()
        # a 600 px progressive-looking test image as the "Spotify cover"
        cover = os.path.join(self.dir, "src.jpg")
        subprocess.run(["ffmpeg", "-v", "error", "-y", "-f", "lavfi", "-i", "testsrc=size=600x600:rate=1", "-frames:v", "1", cover], check=True)
        self.cover = CoverServer(open(cover, "rb").read())
        self.spool = track_spool.TrackSpool(port=0, bitrate=128, spool_dir=self.dir, bind="127.0.0.1")
        self.spool.serve_in_background()
        self.base = f"http://127.0.0.1:{self.spool._server.server_port}"

    def tearDown(self):
        self.cover.close()
        self.spool.close()

    def sine(self, seconds):
        return subprocess.run(["ffmpeg", "-v", "error", "-f", "lavfi", "-i", f"sine=frequency=440:sample_rate=44100:duration={seconds}",
                               "-f", "s16le", "-ac", "2", "-ar", "44100", "pipe:1"], capture_output=True, check=True).stdout

    def test_a_track_is_an_mp3_with_tags_and_the_cover_embedded_and_served_as_a_small_jpeg(self):
        track = self.spool.create({"name": "Sunset", "artists": "The Midnight\nGuest", "album": "Endless Summer", "covers": self.cover.url})
        self.spool.switch(track)

        got = bytearray()
        fetched = threading.Thread(target=lambda: got.extend(urllib.request.urlopen(f"{self.base}/tracks/1.mp3", timeout=30).read()))
        fetched.start()  # the renderer connects while the track is still being written

        pcm = self.sine(2)
        for i in range(0, len(pcm), 3528):
            self.spool.feed(pcm[i:i + 3528])
        self.spool.end()
        fetched.join(timeout=30)

        mp3 = os.path.join(self.dir, "out.mp3")
        open(mp3, "wb").write(got)
        info = probe(mp3)
        tags = {k.lower(): v for k, v in info["format"]["tags"].items()}

        self.assertEqual("Sunset", tags["title"])
        self.assertEqual("The Midnight", tags["artist"])
        self.assertEqual("Endless Summer", tags["album"])
        self.assertAlmostEqual(2.0, float(info["format"]["duration"]), delta=0.3)
        self.assertEqual({"audio", "video"}, {s["codec_type"] for s in info["streams"]}, "cover embedded as attached picture")

        jpg = urllib.request.urlopen(f"{self.base}/tracks/1.jpg", timeout=10).read()
        open(os.path.join(self.dir, "c.jpg"), "wb").write(jpg)
        cover = probe(os.path.join(self.dir, "c.jpg"))["streams"][0]
        self.assertEqual((320, 320), (cover["width"], cover["height"]))
        self.assertEqual("mjpeg", cover["codec_name"])

    def test_a_late_renderer_still_gets_the_whole_track_and_the_next_track_starts_clean(self):
        first = self.spool.create({"name": "One", "artists": "A", "album": "X", "covers": ""})
        second = self.spool.create({"name": "Two", "artists": "B", "album": "Y", "covers": ""})
        self.spool.switch(first)
        pcm = self.sine(1)
        self.spool.feed(pcm)
        self.spool.switch(second)       # the boundary: track 1 ends here
        self.spool.feed(self.sine(1))
        self.spool.end()
        time.sleep(1.5)                 # the renderer only asks once both are long done

        for number, title in ((1, "One"), (2, "Two")):
            path = os.path.join(self.dir, f"late{number}.mp3")
            open(path, "wb").write(urllib.request.urlopen(f"{self.base}/tracks/{number}.mp3", timeout=30).read())
            info = probe(path)
            self.assertEqual(title, {k.lower(): v for k, v in info["format"]["tags"].items()}["title"])
            self.assertAlmostEqual(1.0, float(info["format"]["duration"]), delta=0.3)

        self.assertEqual(404, self.status("/tracks/99.mp3"))
        self.assertEqual(404, self.status("/tracks/1.jpg"))  # no cover for it

    def test_the_event_endpoint_answers_the_item_number_to_local_callers(self):
        r, _ = os.pipe()
        pcm_filler.Splitter(self.spool, r)
        body = b"event=track_changed&name=Song&artists=Artist&album=Album&covers="
        answer = json.loads(urllib.request.urlopen(urllib.request.Request(f"{self.base}/_event", data=body), timeout=5).read())
        self.assertEqual({"item": 1}, answer)
        self.assertIn(1, self.spool.tracks)

    def test_old_tracks_are_dropped(self):
        spool = track_spool.TrackSpool(port=0, keep=2, spool_dir=tempfile.mkdtemp(), bind="127.0.0.1")
        for _ in range(4):
            spool.create({"name": "x"})
        self.assertEqual([3, 4], sorted(spool.tracks))
        spool.close()

    def test_the_filler_end_to_end_cuts_two_tracks_at_the_announced_boundary(self):
        """librespot's PCM in real time, hook events in between: every track is its own MP3 of about its own length."""
        port = 18000 + os.getpid() % 1000
        env = dict(os.environ, TRACKS_ENABLED="true", TRACK_PORT=str(port), STREAM_BITRATE="128")
        filler = subprocess.Popen([sys.executable, os.path.join(os.path.dirname(HERE), "pcm-filler.py")], stdin=subprocess.PIPE,
                                  stdout=subprocess.PIPE, env=env, cwd=os.path.dirname(HERE))
        drain = threading.Thread(target=lambda: [None for _ in iter(lambda: filler.stdout.read(65536), b"")], daemon=True)
        drain.start()
        base = f"http://127.0.0.1:{port}"

        for _ in range(50):  # wait for the server
            try:
                urllib.request.urlopen(base + "/tracks/0.mp3", timeout=1)
            except urllib.error.HTTPError:
                break
            except OSError:
                time.sleep(0.1)

        def announce(name):
            body = f"event=track_changed&name={name}&artists=Artist&album=Album&covers=&duration_ms=2000".encode()
            return json.loads(urllib.request.urlopen(urllib.request.Request(base + "/_event", data=body), timeout=5).read())["item"]

        def play(seconds, value):
            pcm = bytes([value, 0]) * (pcm_filler.RATE * seconds * 2)
            for i in range(0, len(pcm), pcm_filler.CHUNK):
                filler.stdin.write(pcm[i:i + pcm_filler.CHUNK])
                filler.stdin.flush()
                time.sleep(pcm_filler.TICK)

        pcm_one = self.sine(2)
        pcm_two = self.sine(2)
        self.assertEqual(1, announce("One"))
        for i in range(0, len(pcm_one), pcm_filler.CHUNK):
            filler.stdin.write(pcm_one[i:i + pcm_filler.CHUNK]); filler.stdin.flush(); time.sleep(pcm_filler.TICK)
        self.assertEqual(2, announce("Two"))
        for i in range(0, len(pcm_two), pcm_filler.CHUNK):
            filler.stdin.write(pcm_two[i:i + pcm_filler.CHUNK]); filler.stdin.flush(); time.sleep(pcm_filler.TICK)
        # Track 1 ended at the boundary and is complete; track 2 is still open and ends with the stream.
        results = {}

        def fetch(number):
            results[number] = urllib.request.urlopen(f"{base}/tracks/{number}.mp3", timeout=30).read()

        threads = [threading.Thread(target=fetch, args=(n,)) for n in (1, 2)]
        for t in threads:
            t.start()
        time.sleep(1)
        filler.stdin.close()
        for t in threads:
            t.join(timeout=20)
        filler.wait(timeout=15)

        for number, title in ((1, "One"), (2, "Two")):
            path = os.path.join(self.dir, f"e2e{number}.mp3")
            open(path, "wb").write(results[number])
            info = probe(path)
            self.assertEqual(title, {k.lower(): v for k, v in info["format"]["tags"].items()}["title"])
            duration = float(info["format"]["duration"])
            if number == 1:
                self.assertAlmostEqual(2.0, duration, delta=0.5, msg="track 1 is cut at the boundary")
            else:
                # The last track also carries the idle silence the filler keeps writing until the stream ends.
                self.assertGreaterEqual(duration, 1.8, "track 2 has all of its audio")
                self.assertLess(duration, 5.0)

    def status(self, path):
        try:
            return urllib.request.urlopen(self.base + path, timeout=10).status
        except urllib.error.HTTPError as e:
            return e.code


if __name__ == "__main__":
    unittest.main()
