#!/bin/sh
# ReMoment queue soak (workstream 1b): a long run with a mixed load of no-op jobs on every queue the profile runs, recording queue
# health and worker memory, then a report. Runbook: docs/architecture/queue-management.md. Plain POSIX sh.
# It has NEVER been run: the 24-hour gate is the owner's, on the machine (Pi) under test, ideally next to the SQLite stress
# test of scripts/hardware-check (same 24 hours: real listeners and a library scan running meanwhile).
#
#   sh scripts/queue-soak/run.sh --hours 24                 # the real gate, against the running compose stack
#   sh scripts/queue-soak/run.sh --minutes 5                # a smoke run
#   sh scripts/queue-soak/run.sh --dry-run                  # print the plan, run nothing
#
# The no-op jobs (App\Jobs\Soak\SoakNoop: 70 % instant, 15 % short wait, 10 % hold some MB, 5 % fail on purpose) touch no library data.
set -u

here=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$here/../.." && pwd)

MINUTES=60
PER_QUEUE=20
EVERY=60
OUT=""
DRY=0
COMPOSE_FILE=""
PROJECT_DIR=""
APP_SERVICE=${APP_SERVICE:-app}

usage() {
  cat <<'USAGE'
Usage: run.sh [options]
  --hours H | --minutes N   how long to run (default 60 minutes)
  --per-queue N             jobs queued per queue and round (default 20)
  --every SECONDS           seconds between rounds, one sample each (default 60)
  --out DIR                 output folder (default scripts/queue-soak/reports/<host>-<time>)
  --compose-file FILE       compose file (default <repo>/docker-compose.prod.yml)
  --project-dir DIR         compose project directory (default the repo root)
  --dry-run                 print the plan, run nothing
  -h, --help
Env: APP_SERVICE (app).
USAGE
}

while [ $# -gt 0 ]; do
  case $1 in
    --hours) MINUTES=$(( ${2:-0} * 60 )); shift 2 ;;
    --minutes) MINUTES=${2:-}; shift 2 ;;
    --per-queue) PER_QUEUE=${2:-}; shift 2 ;;
    --every) EVERY=${2:-}; shift 2 ;;
    --out) OUT=${2:-}; shift 2 ;;
    --compose-file) COMPOSE_FILE=${2:-}; shift 2 ;;
    --project-dir) PROJECT_DIR=${2:-}; shift 2 ;;
    --dry-run) DRY=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

COMPOSE_FILE=${COMPOSE_FILE:-$repo/docker-compose.prod.yml}
PROJECT_DIR=${PROJECT_DIR:-$repo}
stamp=$(date +%Y%m%d-%H%M%S)
OUT=${OUT:-$here/reports/$(hostname)-$stamp}
rounds=$(( MINUTES * 60 / EVERY ))
[ "$rounds" -ge 1 ] || rounds=1

artisan() {
  docker compose -f "$COMPOSE_FILE" --project-directory "$PROJECT_DIR" exec -T "$APP_SERVICE" php artisan "$@"
}

if [ "$DRY" -eq 1 ]; then
  echo "plan: $rounds rounds, one every ${EVERY}s (about $MINUTES minutes); each round queues $PER_QUEUE no-op jobs on every queue the profile runs,"
  echo "      then appends one sample (queue health, worker memory) to $OUT/samples.jsonl; at the end $OUT/report.md is written."
  echo "      commands: artisan queue:soak enqueue --per-queue=$PER_QUEUE | artisan queue:soak sample | artisan queue:soak report --input=<samples copy>"
  exit 0
fi

mkdir -p "$OUT"
: > "$OUT/samples.jsonl"
echo "queue soak: $rounds rounds of ${EVERY}s into $OUT" >&2

i=0
while [ "$i" -lt "$rounds" ]; do
  artisan queue:soak enqueue --per-queue="$PER_QUEUE" >> "$OUT/enqueue.log" 2>&1 || echo "round $i: enqueue failed" >> "$OUT/errors.log"
  artisan queue:soak sample 2>>"$OUT/errors.log" | tail -n 1 >> "$OUT/samples.jsonl"
  i=$((i + 1))
  [ "$i" -lt "$rounds" ] && sleep "$EVERY"
done

# the report is made by the same code the tests check, in the app container, from the samples file
docker compose -f "$COMPOSE_FILE" --project-directory "$PROJECT_DIR" exec -T "$APP_SERVICE" php artisan queue:soak report --input=/dev/stdin < "$OUT/samples.jsonl" > "$OUT/report.md" 2>>"$OUT/errors.log"
echo "report: $OUT/report.md" >&2
