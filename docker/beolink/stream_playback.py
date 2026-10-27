"""The output of the BeoLink receiver: PCM on stdout instead of a sound card.

beo-adapter's listener hands every received block to a "speaker" (`open()` gives a playback with `write(block)`
and `stop()`). This one writes S16LE stereo 44.1 kHz to stdout, where pcm-filler.py takes over (silence while
nothing plays, then ffmpeg -> Icecast), exactly like librespot's pipe backend.

A source sends 44.1 kHz (M3) or 48 kHz (M5 B&O Radio); the rate is the high word of the header's session field
(0001 / 0002). 48 kHz goes through an ffmpeg resampler whose stdout is ours. The network time stamp of a block is
ignored: the stream has no clock to keep, the speaker buffers anyway.
"""
import logging
import os
import queue
import subprocess
import sys
import threading

log = logging.getLogger("beolink.stream")

OUT_RATE = 44100
RATES = {1: 44100, 2: 48000}


def block_rate(block) -> int:
    """Sample rate of a received FpsBlock, from its session field (0001 = 44.1 kHz, 0002 = 48 kHz)."""
    code = int.from_bytes(bytes(block.session_field[:2]), "big")
    return RATES.get(code, OUT_RATE)


class StreamPlayback:
    """Writes received blocks to an output fd on a thread of its own; the receiver never waits for it."""

    def __init__(self, out_fd=None, depth=256, spawn=subprocess.Popen):
        self.out_fd = sys.stdout.fileno() if out_fd is None else out_fd
        self.q = queue.Queue(maxsize=depth)
        self.spawn = spawn
        self.resampler = None
        self.resampler_rate = None
        self.dropped = 0
        self.written = 0
        self._stop = threading.Event()

    def start(self):
        threading.Thread(target=self._run, daemon=True, name="stream-playback").start()
        return self

    def write(self, block) -> None:
        item = (block_rate(block), block.pcm)
        try:
            self.q.put_nowait(item)
        except queue.Full:
            try:
                self.q.get_nowait()          # the oldest goes
                self.dropped += 1
                self.q.put_nowait(item)
            except (queue.Empty, queue.Full):
                pass

    def stop(self) -> None:
        self._stop.set()
        self._close_resampler()
        log.info("stream playback stopped after %d blocks, %d dropped", self.written, self.dropped)

    def _run(self) -> None:
        while not self._stop.is_set():
            try:
                rate, pcm = self.q.get(timeout=0.2)
            except queue.Empty:
                continue
            try:
                self._emit(rate, pcm)
            except (OSError, ValueError):
                log.warning("stream output closed")
                return
            self.written += 1

    def _emit(self, rate, pcm) -> None:
        if rate == OUT_RATE:
            self._close_resampler()
            os.write(self.out_fd, pcm)
            return
        if self.resampler is None or self.resampler_rate != rate:
            self._close_resampler()
            self.resampler = self.spawn(
                ["ffmpeg", "-hide_banner", "-loglevel", "error", "-f", "s16le", "-ar", str(rate), "-ac", "2",
                 "-i", "-", "-ar", str(OUT_RATE), "-f", "s16le", "-"],
                stdin=subprocess.PIPE, stdout=self.out_fd)
            self.resampler_rate = rate
            log.info("resampling %d Hz -> %d Hz", rate, OUT_RATE)
        self.resampler.stdin.write(pcm)
        self.resampler.stdin.flush()

    def _close_resampler(self) -> None:
        proc, self.resampler, self.resampler_rate = self.resampler, None, None
        if proc is None:
            return
        try:
            proc.stdin.close()
            proc.wait(timeout=2)
        except (OSError, subprocess.TimeoutExpired):
            proc.kill()


class StreamSpeaker:
    """Stands in for beo_adapter.speaker.Speaker: same constructor and methods, no sound card, no mixer.

    The level is ignored on purpose: the stream is passed on at full scale and every speaker has its own volume.
    """

    def __init__(self, device, top=100, control="", **_):
        self.device = device
        self.top = top

    def volume(self, level, muted) -> None:
        pass

    def open(self, now_ns=None) -> StreamPlayback:
        return StreamPlayback().start()
