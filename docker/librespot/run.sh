#!/bin/sh
# Spotify Connect receiver -> raw PCM on a pipe -> silence while idle -> MP3 -> Icecast.
# pcm-filler.py keeps audio flowing when nobody plays, so the mount always exists (see its docstring).
set -u

NAME="${LIBRESPOT_NAME:-ReMoment Stream}"
BITRATE="${LIBRESPOT_BITRATE:-320}"
TARGET="icecast://source:${ICECAST_SOURCE_PASSWORD:-remoment}@${ICECAST_HOST:-127.0.0.1}:${ICECAST_PORT:-8000}/${ICECAST_MOUNT:-spotify.mp3}"

while true; do
    librespot --name "$NAME" --backend pipe --bitrate "$BITRATE" --format S16 --cache /cache \
        --initial-volume 100 --onevent /usr/local/bin/hook.sh \
        | python3 /usr/local/bin/pcm-filler.py \
        | ffmpeg -hide_banner -loglevel warning -f s16le -ar 44100 -ac 2 -i - \
            -c:a libmp3lame -b:a "${STREAM_BITRATE:-320}k" -content_type audio/mpeg -ice_name "$NAME" -f mp3 "$TARGET"
    echo "librespot/ffmpeg exited, restarting in 3 s" >&2
    sleep 3
done
