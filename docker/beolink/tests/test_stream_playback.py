import os
import sys
import time
import unittest
from types import SimpleNamespace

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from stream_playback import StreamPlayback, block_rate  # noqa: E402


def block(code, pcm=b"\x01\x00\x02\x00" * 10):
    return SimpleNamespace(session_field=bytes.fromhex(code), pcm=pcm)


class StreamPlaybackTest(unittest.TestCase):
    def test_rate_comes_from_the_session_field(self):
        self.assertEqual(block_rate(block("00016100")), 44100)
        self.assertEqual(block_rate(block("00026100")), 48000)
        self.assertEqual(block_rate(block("00096100")), 44100)   # unknown code: assume 44.1

    def test_44100_goes_straight_to_the_output(self):
        r, w = os.pipe()
        pb = StreamPlayback(out_fd=w).start()
        pb.write(block("00016100", b"abcd" * 8))
        data = os.read(r, 100)
        pb.stop()
        self.assertEqual(data, b"abcd" * 8)

    def test_48000_goes_through_the_resampler(self):
        spawned = []

        class Proc:
            def __init__(self, args, stdin, stdout):
                spawned.append(args)
                self.stdin = SimpleNamespace(write=lambda b: None, flush=lambda: None, close=lambda: None)

            def wait(self, timeout=None):
                pass

            def kill(self):
                pass

        pb = StreamPlayback(out_fd=1, spawn=Proc).start()
        pb.write(block("00026100"))
        time.sleep(0.3)
        pb.stop()
        self.assertEqual(len(spawned), 1)
        args = spawned[0]
        self.assertEqual(args[args.index("-ar") + 1], "48000")
        self.assertEqual(args[args.index("-ar", args.index("-i")) + 1], "44100")

    def test_full_queue_drops_the_oldest(self):
        pb = StreamPlayback(out_fd=1, depth=2)        # not started: nothing drains
        for i in range(5):
            pb.write(block("00016100", bytes([i]) * 4))
        self.assertEqual(pb.dropped, 3)
        self.assertEqual(pb.q.get_nowait()[1], bytes([3]) * 4)


if __name__ == "__main__":
    unittest.main()
