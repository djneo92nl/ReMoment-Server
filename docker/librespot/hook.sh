#!/bin/sh
# librespot --onevent: tell ReMoment what happened (track, position); the server sets the Icecast title.
# Never blocks or fails the player: short timeouts, errors ignored.
SERVER="${REMOMENT_URL:-http://127.0.0.1}"

curl -s -m 3 -o /dev/null -X POST "$SERVER/api/librespot/event" \
    -H "X-Librespot-Secret: ${LIBRESPOT_EVENT_SECRET:-remoment}" \
    --data-urlencode "event=${PLAYER_EVENT:-}" \
    --data-urlencode "track_id=${TRACK_ID:-}" \
    --data-urlencode "position_ms=${POSITION_MS:-}" \
    --data-urlencode "duration_ms=${DURATION_MS:-}" \
    --data-urlencode "name=${NAME:-}" \
    --data-urlencode "artists=${ARTISTS:-}" \
    --data-urlencode "album=${ALBUM:-}" \
    --data-urlencode "covers=${COVERS:-}" &
exit 0
