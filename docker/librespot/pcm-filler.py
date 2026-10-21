#!/usr/bin/env python3
"""Keep the PCM stream going while Spotify is idle, and (optionally) cut it into one MP3 per track.

librespot's pipe backend writes nothing while paused or between sessions, so ffmpeg never connects to Icecast and
the mount does not exist (a speaker told to play the stream gets a 404). This copies stdin to stdout at real-time
pace (44.1 kHz, 16-bit, stereo) and writes silence for every 20 ms tick in which librespot had nothing to give.
It reads only as fast as it writes, so librespot is held to real time too. EOF on stdin (librespot exited) ends it,
which ends ffmpeg and lets run.sh restart the whole pipeline.

With TRACKS_ENABLED=true the same PCM also feeds track_spool.py: one MP3 per Spotify track, with its own tags and
cover, for renderers that show per-item art. The boundary of a track is exact: when hook.sh reports a track change,
the position is the bytes already read plus those still unread in the pipe, so the tail of the previous track
never lands in the next one.
"""
import array
import collections
import fcntl
import os
import select
import sys
import termios
import threading
import time

RATE, CHANNELS, SAMPLE_BYTES = 44100, 2, 2
FRAME = CHANNELS * SAMPLE_BYTES
TICK = 0.02
CHUNK = int(RATE * TICK) * FRAME            # 20 ms of audio: 3528 bytes, a whole number of frames
MAX_BUFFER = RATE * FRAME // 2              # buffer at most 0.5 s: more is left in the pipe, which blocks librespot
SILENCE = bytes(CHUNK)


def pipe_pending(fd):
    """Bytes written to the pipe and not yet read."""
    buf = array.array("i", [0])
    try:
        fcntl.ioctl(fd, termios.FIONREAD, buf, True)
    except OSError:
        return 0

    return buf[0]


class Splitter:
    """Routes the real PCM (by its position in the stream) and the silence to the spool's current track."""

    def __init__(self, spool, fd):
        self.spool = spool
        self.fd = fd
        self.real_in = 0        # real bytes read from librespot
        self.real_out = 0       # real bytes handed on, i.e. the stream position of the next chunk
        self.pending = collections.deque()  # (stream position where the track begins, track)
        self.lock = threading.Lock()
        spool.on_event = self.on_event

    def read(self, count):
        with self.lock:
            self.real_in += count

    def on_event(self, fields):
        """hook.sh reports a track change (from another thread): remember where in the stream it begins."""
        if fields.get("event") != "track_changed" or not fields.get("name", "").strip():
            return {}

        with self.lock:
            position = self.real_in + pipe_pending(self.fd)

        position -= position % FRAME
        track = self.spool.create(fields)
        self.pending.append((position, track))

        return {"item": track.number}

    def route(self, chunk, real):
        # A boundary at or before the stream position: that track begins now (it may start in silence).
        while self.pending and self.pending[0][0] <= self.real_out:
            self.spool.switch(self.pending.popleft()[1])

        if not real:
            self.spool.feed(chunk)

            return

        start, end, offset = self.real_out, self.real_out + len(chunk), 0

        while self.pending and self.pending[0][0] < end:
            cut = self.pending[0][0] - start
            self.spool.feed(chunk[offset:cut])
            self.spool.switch(self.pending.popleft()[1])
            offset = cut

        self.spool.feed(chunk[offset:])
        self.real_out = end


def main():
    stdin, stdout = sys.stdin.fileno(), sys.stdout.fileno()
    buffer = bytearray()
    next_tick = time.monotonic()
    splitter = None

    if os.environ.get("TRACKS_ENABLED", "false").lower() == "true":
        from track_spool import TrackSpool

        spool = TrackSpool(port=int(os.environ.get("TRACK_PORT", "8010")), bitrate=int(os.environ.get("STREAM_BITRATE", "320")))
        spool.serve_in_background()
        splitter = Splitter(spool, stdin)

    while True:
        if len(buffer) < MAX_BUFFER and select.select([stdin], [], [], 0)[0]:
            data = os.read(stdin, 65536)
            if not data:
                break
            buffer += data
            if splitter:
                splitter.read(len(data))

        real = len(buffer) >= CHUNK
        if real:
            chunk = bytes(buffer[:CHUNK])
            del buffer[:CHUNK]
        else:
            chunk = SILENCE

        try:
            os.write(stdout, chunk)
        except BrokenPipeError:                 # ffmpeg is gone: end, run.sh restarts the pipeline
            break

        if splitter:
            splitter.route(chunk, real)

        next_tick += TICK
        delay = next_tick - time.monotonic()
        if delay > 0:
            time.sleep(delay)
        elif delay < -1:                        # stalled for over a second (a slow consumer): do not rush to catch up
            next_tick = time.monotonic()

    if splitter:
        splitter.spool.end(wait=3)


if __name__ == "__main__":
    main()
