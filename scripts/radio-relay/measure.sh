#!/usr/bin/env bash
# Measures what the radio relay costs: CPU and memory of the ffmpeg children with 1 and with N relays running.
# Run it ON the Pi (or any host), in the app container, with Icecast up. It pins stations with `radio:relay --station=`,
# so RADIO_RELAY_ENABLED does not matter. Usage: measure.sh "<station id> [<station id> ...]" [seconds per step]
# Results go into docs/architecture/radio-relay.md ("Pi 3 measurement"); nothing was measured on a Pi when this was written.
set -euo pipefail

STATIONS=(${1:?usage: measure.sh "<station ids>" [seconds]})
SECONDS_PER_STEP=${2:-120}

sample() { # relays, then the summed CPU % and RSS of all ffmpeg processes, and the load average
  ps -C ffmpeg -o %cpu=,rss= | awk -v n="$1" -v l="$(cut -d' ' -f1 /proc/loadavg)" '{c+=$1; m+=$2} END {printf "%s relays: cpu %.1f%%  rss %.0f MB  load %s\n", n, c, m/1024, l}'
}

for count in 1 "${#STATIONS[@]}"; do
  args=()
  for id in "${STATIONS[@]:0:$count}"; do args+=(--station="$id"); done
  nice -n 10 php artisan radio:relay "${args[@]}" >/dev/null 2>&1 &
  pid=$!
  sleep 20 # the mounts come up within about 15 s
  end=$((SECONDS + SECONDS_PER_STEP))
  while [ $SECONDS -lt $end ]; do sample "$count"; sleep 10; done
  kill -TERM "$pid"; wait "$pid" 2>/dev/null || true
  [ "$count" = "${#STATIONS[@]}" ] && break
done
