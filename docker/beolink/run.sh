#!/bin/sh
# B&O NetworkLink listener -> PCM on stdout -> silence while idle -> MP3 -> Icecast.
# Same tail as the Spotify stream (docker/librespot/run.sh); pcm-filler.py keeps the mount alive.
set -u

NAME="${BEOLINK_NAME:-ReMoment Stream}"
TARGET="icecast://source:${ICECAST_SOURCE_PASSWORD:-remoment}@${ICECAST_HOST:-127.0.0.1}:${ICECAST_PORT:-8000}/${ICECAST_BEOLINK_MOUNT:-beolink.mp3}"

while true; do
    python3 /usr/local/bin/receiver.py \
        | python3 /usr/local/bin/pcm-filler.py \
        | ffmpeg -hide_banner -loglevel warning -f s16le -ar 44100 -ac 2 -i - \
            -c:a libmp3lame -b:a "${STREAM_BITRATE:-192}k" -content_type audio/mpeg -ice_name "$NAME" -f mp3 "$TARGET"
    echo "beolink receiver/ffmpeg exited, restarting in 3 s" >&2
    sleep 3
done
