#!/bin/sh
# librespot --onevent: tell ReMoment what happened (track, position); the server sets the Icecast title.
# Never blocks or fails the player: short timeouts, errors ignored.
SERVER="${REMOMENT_URL:-http://127.0.0.1}"
ITEM=""

# Per-track items: the splitter in this container learns the boundary first (it answers with the item number of the
# new track), in order and before anything else, because the position in the audio stream is only exact right now.
if [ "${TRACKS_ENABLED:-false}" = "true" ] && [ "${PLAYER_EVENT:-}" = "track_changed" ]; then
    RESPONSE=$(curl -s -m 2 -X POST "http://127.0.0.1:${TRACK_PORT:-8010}/_event" \
        --data-urlencode "event=${PLAYER_EVENT:-}" \
        --data-urlencode "name=${NAME:-}" \
        --data-urlencode "artists=${ARTISTS:-}" \
        --data-urlencode "album=${ALBUM:-}" \
        --data-urlencode "covers=${COVERS:-}" \
        --data-urlencode "duration_ms=${DURATION_MS:-}")
    ITEM=$(printf '%s' "$RESPONSE" | sed -n 's/.*"item": *\([0-9][0-9]*\).*/\1/p')
fi

curl -s -m 3 -o /dev/null -X POST "$SERVER/api/librespot/event" \
    -H "X-Librespot-Secret: ${LIBRESPOT_EVENT_SECRET:-remoment}" \
    --data-urlencode "event=${PLAYER_EVENT:-}" \
    --data-urlencode "track_id=${TRACK_ID:-}" \
    --data-urlencode "position_ms=${POSITION_MS:-}" \
    --data-urlencode "duration_ms=${DURATION_MS:-}" \
    --data-urlencode "name=${NAME:-}" \
    --data-urlencode "artists=${ARTISTS:-}" \
    --data-urlencode "album=${ALBUM:-}" \
    --data-urlencode "covers=${COVERS:-}" \
    --data-urlencode "item=${ITEM}" &
exit 0
