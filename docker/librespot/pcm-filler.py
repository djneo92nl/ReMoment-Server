#!/usr/bin/env python3
"""Keep the PCM stream going while Spotify is idle.

librespot's pipe backend writes nothing while paused or between sessions, so ffmpeg never connects to Icecast and
the mount does not exist (a speaker told to play the stream gets a 404). This copies stdin to stdout at real-time
pace (44.1 kHz, 16-bit, stereo) and writes silence for every 20 ms tick in which librespot had nothing to give.
It reads only as fast as it writes, so librespot is held to real time too. EOF on stdin (librespot exited) ends it,
which ends ffmpeg and lets run.sh restart the whole pipeline.
"""
import os
import select
import sys
import time

RATE, CHANNELS, SAMPLE_BYTES = 44100, 2, 2
FRAME = CHANNELS * SAMPLE_BYTES
TICK = 0.02
CHUNK = int(RATE * TICK) * FRAME            # 20 ms of audio: 3528 bytes, a whole number of frames
MAX_BUFFER = RATE * FRAME // 2              # buffer at most 0.5 s: more is left in the pipe, which blocks librespot
SILENCE = bytes(CHUNK)

stdin, stdout = sys.stdin.fileno(), sys.stdout.fileno()
buffer = bytearray()
next_tick = time.monotonic()

while True:
    if len(buffer) < MAX_BUFFER and select.select([stdin], [], [], 0)[0]:
        data = os.read(stdin, 65536)
        if not data:
            break
        buffer += data

    if len(buffer) >= CHUNK:
        chunk = bytes(buffer[:CHUNK])
        del buffer[:CHUNK]
    else:
        chunk = SILENCE

    try:
        os.write(stdout, chunk)
    except BrokenPipeError:                 # ffmpeg is gone: end, run.sh restarts the pipeline
        break

    next_tick += TICK
    delay = next_tick - time.monotonic()
    if delay > 0:
        time.sleep(delay)
    elif delay < -1:                        # stalled for over a second (a slow consumer): do not rush to catch up
        next_tick = time.monotonic()
