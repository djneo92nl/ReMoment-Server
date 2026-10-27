#!/usr/bin/env python3
"""BeoLink receiver: shows up as a B&O product, joins a source's experience and writes its PCM to stdout.

It is beo-adapter (djerik/beo, Apache-2.0) with a plain speaker behind it (`--machine none`, no bus) and
`StreamSpeaker` in place of the sound card. Everything else is beo's: the Mozart REST surface the B&O app and
ReMoment talk to (`POST /api/v1/beolink/join/<jid>`, `/leave`, `/peers`, `/listeners`), the beocore listener,
mDNS, and optionally (`BEO_ENROL`) the entry in the network's shared configuration.

stdout is audio only; beo logs to stderr.
"""
import os
import sys


def main() -> int:
    import beo_adapter.speaker as speaker_module
    from stream_playback import StreamSpeaker

    speaker_module.Speaker = StreamSpeaker      # main.py imports it inside main(), so this is seen
    from beo_adapter.main import main as adapter_main

    argv = ["--machine", "none", "--ml", "none", "--beolink", "--listen", os.environ.get("BEO_LISTEN", "on"),
            "--speaker-device", "stream", "--port", os.environ.get("BEOLINK_API_PORT", "8081"),
            "--name", os.environ.get("BEOLINK_NAME", "ReMoment Stream")]
    if os.environ.get("BEO_ENROL", "").lower() in ("1", "true", "yes", "on"):
        argv.append("--enrol")
    return adapter_main(argv + sys.argv[1:])


if __name__ == "__main__":
    sys.exit(main())
