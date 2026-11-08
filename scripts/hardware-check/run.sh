#!/bin/sh
# ReMoment hardware check: measures a provisioned Raspberry Pi (or any Linux host) running the ReMoment stack.
# Runbook: docs/architecture/hardware-check.md. Plain POSIX sh + curl, awk, free/proc, df, docker (+ vcgencmd when present).
# Nothing here has run on a Pi yet. Budgets (budgets.conf) are proposals.
#
#   sh scripts/hardware-check/run.sh --profile control --url http://localhost
#   sh scripts/hardware-check/run.sh --profile control --hours 24        # the real SQLite gate
#   sh scripts/hardware-check/run.sh --profile standard --skip-stress
#   sh scripts/hardware-check/run.sh --dry-run --profile control         # print the plan, run nothing
#   sh scripts/hardware-check/run.sh --self-test                         # report generator on canned data (any machine)
#
# Every step keeps going when the one before failed; failed steps are listed in the report.
set -u

here=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$here/../.." && pwd)

PROFILE_UNDER_TEST=""
BASE_URL="http://localhost"
MINUTES=10
OUT=""
DRY=0
SELF_TEST=0
SKIP_STRESS=0
IDLE_SECONDS=${IDLE_SECONDS:-60}
SAMPLE_SECONDS=${SAMPLE_SECONDS:-30}
REQUESTS=${REQUESTS:-20}
COMPOSE_FILE=""
PROJECT_DIR=""
APP_SERVICE=${APP_SERVICE:-app}

usage() {
  cat <<'USAGE'
Usage: run.sh [options]
  --profile control|standard|full   profile of the server under test (default: what GET /api/info reports)
  --url URL                         base URL of the server (default http://localhost)
  --minutes N                       stress test duration in minutes (default 10)
  --hours H                         stress test duration in hours (--hours 24 is the real gate)
  --out DIR                         output folder (default scripts/hardware-check/reports/<host>-<profile>-<time>)
  --skip-stress                     leave the stress test out
  --compose-file FILE               compose file (default <repo>/docker-compose.prod.yml)
  --project-dir DIR                 compose project directory (default the repo root)
  --dry-run                         print what each step would do, run nothing
  --self-test                       run the report generator against canned sample data
  -h, --help
Env: IDLE_SECONDS (60), SAMPLE_SECONDS (30), REQUESTS (20), APP_SERVICE (app).
USAGE
}

while [ $# -gt 0 ]; do
  case $1 in
    --profile) PROFILE_UNDER_TEST=${2:-}; shift 2 ;;
    --url) BASE_URL=${2:-}; shift 2 ;;
    --minutes) MINUTES=${2:-}; shift 2 ;;
    --hours) MINUTES=$(( ${2:-0} * 60 )); shift 2 ;;
    --out) OUT=${2:-}; shift 2 ;;
    --skip-stress) SKIP_STRESS=1; shift ;;
    --compose-file) COMPOSE_FILE=${2:-}; shift 2 ;;
    --project-dir) PROJECT_DIR=${2:-}; shift 2 ;;
    --dry-run) DRY=1; shift ;;
    --self-test) SELF_TEST=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

case $PROFILE_UNDER_TEST in ''|control|standard|full) ;; *) echo "--profile must be control, standard or full" >&2; exit 2 ;; esac
case $MINUTES in ''|*[!0-9]*) echo "--minutes/--hours need a whole number" >&2; exit 2 ;; esac
[ "$MINUTES" -ge 1 ] || { echo "duration must be at least 1 minute" >&2; exit 2; }
BASE_URL=${BASE_URL%/}
[ -n "$COMPOSE_FILE" ] || COMPOSE_FILE=$repo/docker-compose.prod.yml
[ -n "$PROJECT_DIR" ] || PROJECT_DIR=$repo

# ------------------------------------------------------------------ helpers

say() { printf '%s\n' "$*"; }
now_iso() { date -u +%Y-%m-%dT%H:%M:%SZ; }

# put KEY VALUE -> appends KEY='VALUE' to results.env (quotes escaped)
put() {
  v=$(printf '%s' "$2" | sed "s/'/'\\\\''/g")
  printf "%s='%s'\n" "$1" "$v" >> "$OUT/results.env"
}

STEPS_FAILED=""
note_failed() { STEPS_FAILED="${STEPS_FAILED:+$STEPS_FAILED; }$1"; }

dc() { docker compose -f "$COMPOSE_FILE" --project-directory "$PROJECT_DIR" "$@"; }

# Memory in use (MemTotal - MemAvailable) in MB; empty when /proc/meminfo is missing (not Linux).
mem_used_mb() {
  [ -r /proc/meminfo ] || return 1
  awk '/^MemTotal:/{t=$2} /^MemAvailable:/{a=$2} END{ if (t>0 && a>0) printf "%d\n", (t-a)/1024 }' /proc/meminfo
}
mem_total_mb() { [ -r /proc/meminfo ] && awk '/^MemTotal:/{printf "%d\n", $2/1024}' /proc/meminfo; }
swap_total_mb() { [ -r /proc/meminfo ] && awk '/^SwapTotal:/{printf "%d\n", $2/1024}' /proc/meminfo; }

# CPU temperature in whole-and-tenth degrees C.
cpu_temp_c() {
  if command -v vcgencmd >/dev/null 2>&1; then
    vcgencmd measure_temp 2>/dev/null | sed -n "s/^temp=\([0-9.]*\).*/\1/p"
  elif [ -r /sys/class/thermal/thermal_zone0/temp ]; then
    awk '{printf "%.1f\n", $1/1000}' /sys/class/thermal/thermal_zone0/temp
  fi
}
throttled() { command -v vcgencmd >/dev/null 2>&1 && vcgencmd get_throttled 2>/dev/null | sed -n 's/^throttled=//p'; }

# stats_ms <file of "seconds code" lines> -> "median_ms p95_ms status"
# status is 200 when every request answered 200, else the first other code.
stats_ms() {
  awk '
    { ms[NR] = $1 * 1000; if ($2 != 200 && bad == "") bad = $2 }
    END {
      n = NR; if (n == 0) { print "- - none"; exit }
      for (i = 1; i <= n; i++) v[i] = ms[i]
      for (i = 2; i <= n; i++) { x = v[i]; j = i - 1; while (j > 0 && v[j] > x) { v[j+1] = v[j]; j-- } v[j+1] = x }
      med = (n % 2) ? v[(n+1)/2] : (v[n/2] + v[n/2+1]) / 2
      k = int(0.95 * n); if (k < 0.95 * n) k++; if (k < 1) k = 1
      printf "%d %d %s\n", med + 0.5, v[k] + 0.5, (bad == "" ? "200" : bad)
    }' "$1"
}

# avg_max <file of "epoch value" lines> -> "avg max" (integers)
avg_max() {
  awk 'NF>=2 && $2 ~ /^[0-9.]+$/ { s += $2; n++; if ($2 > m) m = $2 } END { if (n) printf "%d %d\n", s/n + 0.5, m + 0.5; else print "- -" }' "$1"
}

# ------------------------------------------------------------------ steps

step_host() {
  say "== (a) host facts"
  if [ "$DRY" = 1 ]; then
    say "   would read: /proc/device-tree/model, uname -m, uname -r, /proc/meminfo (RAM, swap), df -m /, root device type (lsblk or /sys/block), vcgencmd measure_temp and get_throttled"
    return 0
  fi
  put host_model "$( [ -r /proc/device-tree/model ] && tr -d '\000' < /proc/device-tree/model || echo unknown )"
  put host_arch "$(uname -m)"
  put host_kernel "$(uname -r)"
  put ram_total_mb "$(mem_total_mb)"
  put swap_total_mb "$(swap_total_mb)"
  put disk_free_mb "$(df -Pm / 2>/dev/null | awk 'NR==2{print $4}')"
  # storage type: mmcblk = SD card, nvme/sd* = SSD or USB disk (rotational flag when readable)
  src=$(df -P / 2>/dev/null | awk 'NR==2{print $1}')
  base=$(basename "$src" | sed 's/p\{0,1\}[0-9]*$//')
  case $src in
    *mmcblk*) st="SD card (mmcblk)" ;;
    *nvme*) st="NVMe" ;;
    *) st="${base:-unknown}"
       if [ -r "/sys/block/$base/queue/rotational" ]; then
         [ "$(cat "/sys/block/$base/queue/rotational")" = 1 ] && st="$st, rotational disk" || st="$st, non-rotational (SSD/USB flash)"
       fi ;;
  esac
  put storage_type "$st ($src)"
  put temp_start_c "$(cpu_temp_c)"
  put throttled_start "$(throttled)"
}

step_stack() {
  say "== (b) stack facts"
  if [ "$DRY" = 1 ]; then
    say "   would run: docker compose -f $COMPOSE_FILE ps; docker inspect + docker image inspect (architecture per image); docker stats --no-stream; curl $BASE_URL/api/info (features.profile)"
    return 0
  fi
  if ! command -v docker >/dev/null 2>&1; then note_failed "stack: docker not found"; return 1; fi
  dc ps > "$OUT/compose-ps.txt" 2>&1 || note_failed "stack: docker compose ps failed (see compose-ps.txt)"
  ids=$(dc ps -q 2>/dev/null)
  put stack_containers "$(dc ps --format '{{.Service}}={{.State}}' 2>/dev/null | tr '\n' ' ')"
  archs=""
  for id in $ids; do
    img=$(docker inspect -f '{{.Config.Image}}' "$id" 2>/dev/null)
    a=$(docker image inspect -f '{{.Architecture}}{{if .Variant}}/{{.Variant}}{{end}}' "$img" 2>/dev/null)
    archs="$archs$img=${a:-?}; "
  done
  put stack_image_arch "$archs"
  # shellcheck disable=SC2086
  [ -n "$ids" ] && docker stats --no-stream --format 'table {{.Name}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.CPUPerc}}' $ids > "$OUT/docker-stats-start.txt" 2>&1
  info=$(curl -s -m 15 "$BASE_URL/api/info" 2>/dev/null)
  printf '%s\n' "$info" > "$OUT/api-info.txt"
  detected=$(printf '%s' "$info" | sed -n 's/.*"profile" *: *"\([a-z]*\)".*/\1/p')
  put stack_profile "$detected"
  if [ -z "$detected" ]; then note_failed "stack: GET /api/info did not answer a profile"; fi
  if [ -z "$PROFILE_UNDER_TEST" ]; then PROFILE_UNDER_TEST=$detected; fi
}

step_idle() {
  say "== (c) idle memory (${IDLE_SECONDS}s, every 5s; leave the stack alone)"
  if [ "$DRY" = 1 ]; then say "   would sample MemTotal-MemAvailable from /proc/meminfo every 5 s for ${IDLE_SECONDS} s"; return 0; fi
  : > "$OUT/idle.samples"
  i=0
  while [ "$i" -lt "$IDLE_SECONDS" ]; do
    m=$(mem_used_mb) || { note_failed "idle: no /proc/meminfo (not Linux)"; return 1; }
    echo "$(date +%s) $m" >> "$OUT/idle.samples"
    sleep 5; i=$((i + 5))
  done
  set -- $(avg_max "$OUT/idle.samples")
  put idle_mem_used_mb_avg "$1"
  put idle_mem_used_mb_max "$2"
}

# one_timing <key> <path>
one_timing() {
  key=$1; path=$2
  put "timing_${key}_path" "GET $path"
  curl -s -o /dev/null -m 60 "$BASE_URL$path" >/dev/null 2>&1          # warm-up, not counted
  : > "$OUT/timing-$key.times"
  n=0
  while [ "$n" -lt "$REQUESTS" ]; do
    curl -s -o /dev/null -m 60 -w '%{time_total} %{http_code}\n' "$BASE_URL$path" >> "$OUT/timing-$key.times" 2>/dev/null \
      || echo "60 000" >> "$OUT/timing-$key.times"
    n=$((n + 1))
  done
  set -- $(stats_ms "$OUT/timing-$key.times")
  put "timing_${key}_median_ms" "$1"
  put "timing_${key}_p95_ms" "$2"
  put "timing_${key}_status" "$3"
}

skip_timing() { put "timing_$1_path" "GET $2"; put "timing_$1_status" skipped; put "timing_$1_why" "$3"; }

# burst <path>: 10 concurrent requests, 3 rounds, memory sampled while they run -> peak MB in $OUT/burst.samples
burst() {
  path=$1
  round=0
  while [ "$round" -lt 3 ]; do
    (
      k=0
      while [ "$k" -lt 10 ]; do curl -s -o /dev/null -m 60 "$BASE_URL$path" >/dev/null 2>&1 & k=$((k + 1)); done
      wait
    ) &
    bp=$!
    while kill -0 "$bp" 2>/dev/null; do
      m=$(mem_used_mb) && echo "$(date +%s) $m" >> "$OUT/burst.samples"
      sleep 0.3
    done
    wait "$bp" 2>/dev/null
    round=$((round + 1))
  done
}

step_web() {
  say "== (d) web timings (warm-up + $REQUESTS requests each, median and p95; then a 10-request burst)"
  prof=${PROFILE_UNDER_TEST:-standard}
  if [ "$DRY" = 1 ]; then
    say "   would time with curl: /api/info, /api/devices"
    if [ "$prof" = control ]; then say "   (profile control: /devices would be timed; /library and /api/library/albums skipped, not in Control)"
    else say "   and /devices, /library, /api/library/albums"; fi
    say "   then 3 rounds of 10 concurrent requests to /devices, sampling memory every 0.3 s"
    return 0
  fi
  command -v curl >/dev/null 2>&1 || { note_failed "web: curl not found"; return 1; }
  one_timing api_info /api/info
  one_timing api_devices /api/devices
  one_timing devices /devices
  if [ "$prof" = control ]; then
    skip_timing library /library "not part of the Control profile"
    skip_timing api_library_albums /api/library/albums "not part of the Control profile"
  else
    one_timing library /library
    one_timing api_library_albums /api/library/albums
  fi
  : > "$OUT/burst.samples"
  burst /devices
  set -- $(avg_max "$OUT/burst.samples")
  put burst_mem_peak_mb "$2"
}

step_stress() {
  say "== (e) SQLite stress test (${MINUTES} min, 6 listeners; Control only)"
  prof=${PROFILE_UNDER_TEST:-unknown}
  if [ "$prof" != control ]; then
    say "   skipped: profile:stress-sqlite is the Control gate (SQLite); the profile under test is '$prof'."
    if [ "$DRY" != 1 ]; then put stress_ran no; put stress_why "only the Control profile uses SQLite (profile is $prof)"; fi
    return 0
  fi
  if [ "$SKIP_STRESS" = 1 ]; then
    say "   skipped: --skip-stress"
    [ "$DRY" != 1 ] && { put stress_ran no; put stress_why "--skip-stress"; }
    return 0
  fi
  if [ "$DRY" = 1 ]; then
    say "   would run: docker compose -f $COMPOSE_FILE exec -T $APP_SERVICE php artisan profile:stress-sqlite --minutes=$MINUTES --listeners=6"
    say "   and sample memory and CPU temperature every ${SAMPLE_SECONDS} s until it ends; then read the verdict and the 'database is locked' count from its table"
    return 0
  fi
  if ! command -v docker >/dev/null 2>&1; then note_failed "stress: docker not found"; put stress_ran no; put stress_why "docker not found"; return 1; fi
  cid=$(dc ps -q "$APP_SERVICE" 2>/dev/null | head -n 1)
  if [ -z "$cid" ]; then note_failed "stress: no running '$APP_SERVICE' container"; put stress_ran no; put stress_why "no running $APP_SERVICE container"; return 1; fi
  put stress_container "$cid"
  : > "$OUT/stress.samples"
  dc exec -T "$APP_SERVICE" php artisan profile:stress-sqlite --minutes="$MINUTES" --listeners=6 > "$OUT/stress.txt" 2>&1 &
  spid=$!
  while kill -0 "$spid" 2>/dev/null; do
    echo "$(date +%s) $(mem_used_mb) $(cpu_temp_c)" >> "$OUT/stress.samples"
    s=0; while [ "$s" -lt "$SAMPLE_SECONDS" ] && kill -0 "$spid" 2>/dev/null; do sleep 1; s=$((s + 1)); done
  done
  wait "$spid"; rc=$?
  put stress_ran yes
  put stress_minutes "$MINUTES"
  put stress_exit_code "$rc"
  verdict=$(sed -n 's/^\(PASS\|FAIL\).*/\1/p' "$OUT/stress.txt" | tail -n 1)
  [ -n "$verdict" ] || { verdict=""; note_failed "stress: no PASS/FAIL line in stress.txt (exit code $rc)"; }
  put stress_verdict "$verdict"
  put stress_locked "$(grep 'database is locked' "$OUT/stress.txt" | head -n 1 | awk -F'|' '{gsub(/[ ,]/, "", $3); print $3}')"
  put stress_mem_peak_mb "$(awk 'NF>=2 && $2+0 > m {m=$2+0} END{print m+0}' "$OUT/stress.samples")"
  put stress_temp_max_c "$(awk 'NF>=3 && $3 ~ /^[0-9.]+$/ && $3+0 > m {m=$3+0; seen=1} END{ if (seen) print m }' "$OUT/stress.samples")"
  put stress_throttled_end "$(throttled)"
}

step_logs() {
  say "== (f) log scan (container logs since the run started)"
  if [ "$DRY" = 1 ]; then
    say "   would run: docker compose logs --no-color --since <start> and count 'database is locked', 'OOM', 'Killed', 'MaxAttemptsExceeded', 'Connection refused'"
    return 0
  fi
  if ! command -v docker >/dev/null 2>&1; then note_failed "logs: docker not found"; return 1; fi
  if ! dc logs --no-color --since "$STARTED_AT" > "$OUT/container-logs.txt" 2>&1; then note_failed "logs: docker compose logs failed"; fi
  put log_locked "$(grep -c 'database is locked' "$OUT/container-logs.txt")"
  put log_oom "$(grep -c 'OOM' "$OUT/container-logs.txt")"
  put log_killed "$(grep -c 'Killed' "$OUT/container-logs.txt")"
  put log_maxattempts "$(grep -c 'MaxAttemptsExceeded' "$OUT/container-logs.txt")"
  put log_refused "$(grep -c 'Connection refused' "$OUT/container-logs.txt")"
}

# ------------------------------------------------------------------ self test

self_test() {
  fail=0
  t=$(mktemp -d "${TMPDIR:-/tmp}/hwcheck-selftest.XXXXXX") || exit 1
  ok() { say "  ok   $*"; }
  bad() { say "  FAIL $*"; fail=1; }
  expect() { if grep -q -- "$2" "$1"; then ok "$3"; else bad "$3 (missing: $2)"; fi; }

  say "-- helper math"
  # 20 samples 0.100 .. 2.000 s, all 200 -> median 1050, p95 = 19th value = 1900
  i=1; : > "$t/times"; while [ $i -le 20 ]; do awk -v i=$i 'BEGIN{printf "%.3f 200\n", i/10}' >> "$t/times"; i=$((i+1)); done
  [ "$(stats_ms "$t/times")" = "1050 1900 200" ] && ok "median/p95 of 20 samples" || bad "stats_ms gave: $(stats_ms "$t/times")"
  printf '0.1 200\n0.2 500\n0.3 200\n' > "$t/times3"
  [ "$(stats_ms "$t/times3")" = "200 300 500" ] && ok "non-200 status is reported" || bad "stats_ms gave: $(stats_ms "$t/times3")"
  printf '1 100\n2 300\n' > "$t/s"
  [ "$(avg_max "$t/s")" = "200 300" ] && ok "avg_max" || bad "avg_max gave: $(avg_max "$t/s")"

  say "-- canned PASS run (Pi 3, control)"
  mkdir "$t/pass"
  cat > "$t/pass/results.env" <<'EOF'
PROFILE_UNDER_TEST='control'
base_url='http://pi3.local'
started_at='2026-01-01T10:00:00Z'
finished_at='2026-01-01T10:20:00Z'
host_model='Raspberry Pi 3 Model B Rev 1.2'
host_arch='aarch64'
host_kernel='6.6.31+rpt-rpi-v8'
ram_total_mb='906'
swap_total_mb='100'
disk_free_mb='21000'
storage_type='SD card (mmcblk) (/dev/mmcblk0p2)'
temp_start_c='48.2'
throttled_start='0x0'
stack_profile='control'
stack_image_arch='remoment/app:latest=arm64; redis:alpine=arm64; eclipse-mosquitto:2=arm64; '
stack_containers='app=running mosquitto=running redis=running '
idle_mem_used_mb_avg='410'
idle_mem_used_mb_max='415'
timing_api_info_path='GET /api/info'
timing_api_info_median_ms='60'
timing_api_info_p95_ms='90'
timing_api_info_status='200'
timing_api_devices_path='GET /api/devices'
timing_api_devices_median_ms='120'
timing_api_devices_p95_ms='210'
timing_api_devices_status='200'
timing_devices_path='GET /devices'
timing_devices_median_ms='700'
timing_devices_p95_ms='1200'
timing_devices_status='200'
timing_library_path='GET /library'
timing_library_status='skipped'
timing_library_why='not part of the Control profile'
burst_mem_peak_mb='560'
stress_ran='yes'
stress_minutes='1440'
stress_verdict='PASS'
stress_locked='0'
stress_mem_peak_mb='480'
stress_temp_max_c='66.4'
stress_throttled_end='0x0'
log_locked='0'
log_oom='0'
log_killed='0'
log_maxattempts='0'
log_refused='0'
EOF
  sh "$here/report.sh" "$t/pass" > "$t/pass.out" 2>&1 || bad "report.sh failed: $(cat "$t/pass.out")"
  expect "$t/pass/report.md" '0 fail' "PASS run has no failures"
  expect "$t/pass/report.md" 'GET /devices p95 (ms) | 1200 | < 2000 | pass' "devices p95 labelled pass"
  expect "$t/pass/report.md" 'skipped: not part of the Control profile' "skipped endpoint shown"
  expect "$t/pass/report.md" 'proposals' "budgets flagged as proposals"
  expect "$t/pass/report.json" '"fail": 0' "json summary"
  if command -v python3 >/dev/null 2>&1; then
    python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "$t/pass/report.json" && ok "report.json parses" || bad "report.json is not valid JSON"
  fi

  say "-- canned FAIL run (Pi 2, control: locked, hot, throttled, slow, 32-bit)"
  mkdir "$t/fail"
  sed -e "s/^host_model=.*/host_model='Raspberry Pi 2 Model B Rev 1.1'/" \
      -e "s/^host_arch=.*/host_arch='armv7l'/" \
      -e "s/^idle_mem_used_mb_avg=.*/idle_mem_used_mb_avg='640'/" \
      -e "s/^timing_devices_p95_ms=.*/timing_devices_p95_ms='3400'/" \
      -e "s/^stress_verdict=.*/stress_verdict='FAIL'/" \
      -e "s/^stress_locked=.*/stress_locked='3'/" \
      -e "s/^stress_minutes=.*/stress_minutes='10'/" \
      -e "s/^stress_temp_max_c=.*/stress_temp_max_c='82.0'/" \
      -e "s/^stress_throttled_end=.*/stress_throttled_end='0x50005'/" \
      -e "s/^log_locked=.*/log_locked='2'/" \
      -e "s/^stack_profile=.*/stack_profile='standard'/" \
      "$t/pass/results.env" > "$t/fail/results.env"
  printf "steps_failed='stack: example failure'\n" >> "$t/fail/results.env"
  sh "$here/report.sh" "$t/fail" > "$t/fail.out" 2>&1 || bad "report.sh failed on the FAIL data: $(cat "$t/fail.out")"
  expect "$t/fail/report.md" 'GET /devices p95 (ms) | 3400 | < 2000 | \*\*FAIL\*\*' "slow devices page fails"
  expect "$t/fail/report.md" 'Idle memory in use, 60 s average (MB) | 640 | < 600' "idle memory over the Control budget fails"
  expect "$t/fail/report.md" 'database is locked (command output) | 3' "locked count reported"
  expect "$t/fail/report.md" '0x50005 | 0x0 | \*\*FAIL\*\*' "throttling fails"
  expect "$t/fail/report.md" 'Duration (minutes) | 10 | 1440 (24 h) is the real gate | \*\*warn\*\*' "short stress run only warns"
  expect "$t/fail/report.md" 'Profile reported by /api/info | standard | = control | \*\*FAIL\*\*' "profile mismatch fails"
  expect "$t/fail/report.md" 'Steps that failed or were skipped: stack: example failure' "failed steps listed"
  if command -v python3 >/dev/null 2>&1; then
    python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "$t/fail/report.json" && ok "fail report.json parses" || bad "fail report.json is not valid JSON"
  fi

  say "-- empty results (every step failed): report must still be written"
  mkdir "$t/empty"; printf "PROFILE_UNDER_TEST='standard'\n" > "$t/empty/results.env"
  sh "$here/report.sh" "$t/empty" > "$t/empty.out" 2>&1 && [ -s "$t/empty/report.md" ] && ok "report written from nothing" || bad "report.sh died on empty data: $(cat "$t/empty.out")"
  expect "$t/empty/report.md" 'not measured' "unmeasured rows labelled"

  rm -rf "$t"
  if [ "$fail" = 0 ]; then say "self-test: all good"; exit 0; fi
  say "self-test: FAILURES"; exit 1
}

if [ "$SELF_TEST" = 1 ]; then self_test; fi

# ------------------------------------------------------------------ main

STARTED_AT=$(now_iso)
if [ -z "$OUT" ]; then
  OUT=$here/reports/$(hostname -s 2>/dev/null || echo host)-${PROFILE_UNDER_TEST:-auto}-$(date +%Y%m%d-%H%M%S)
fi

say "ReMoment hardware check"
say "  profile:   ${PROFILE_UNDER_TEST:-auto-detect from /api/info}"
say "  base URL:  $BASE_URL"
say "  stress:    $MINUTES min (control only)$( [ "$SKIP_STRESS" = 1 ] && echo ', skipped')"
say "  output:    $OUT"
say "  compose:   $COMPOSE_FILE (project dir $PROJECT_DIR)"
[ "$DRY" = 1 ] && say "  DRY RUN: nothing below is executed, nothing is written"
say ""

if [ "$DRY" != 1 ]; then
  mkdir -p "$OUT" || { echo "cannot create $OUT" >&2; exit 1; }
  : > "$OUT/results.env"
  put base_url "$BASE_URL"
  put started_at "$STARTED_AT"
fi

step_host   || note_failed "host facts"
step_stack  || note_failed "stack facts"
# a profile given on the command line wins over the detected one; the report flags a mismatch
step_idle   || note_failed "idle memory"
step_web    || note_failed "web timings"
step_stress || note_failed "stress test"
step_logs   || note_failed "log scan"

if [ "$DRY" = 1 ]; then
  say ""
  say "== report: would write report.json and report.md to $OUT (budgets from $here/budgets.conf)"
  exit 0
fi

put finished_at "$(now_iso)"
put PROFILE_UNDER_TEST "${PROFILE_UNDER_TEST:-}"
put steps_failed "$STEPS_FAILED"
say ""
sh "$here/report.sh" "$OUT"
say "Read $OUT/report.md"
