#!/bin/sh
# Turns a results folder (results.env + raw files, written by run.sh) into report.json and report.md.
# Usage: sh report.sh <results-dir> [budgets.conf]
# Plain POSIX sh and awk; nothing to install. Budgets are proposals (see budgets.conf).
set -u
dir=${1:?usage: report.sh <results-dir> [budgets.conf]}
here=$(cd "$(dirname "$0")" && pwd)
budgets=${2:-$here/budgets.conf}
[ -f "$dir/results.env" ] || { echo "no $dir/results.env" >&2; exit 2; }
# shellcheck disable=SC1090
. "$budgets"
# shellcheck disable=SC1090,SC1091
. "$dir/results.env"

g() { eval "printf '%s' \"\${$1:-}\""; }          # value of a variable by name, empty when unset
num() { case $1 in ''|*[!0-9.]*) return 1 ;; *) return 0 ;; esac; }
md=$dir/report.md
js=$dir/report.json
rows=$dir/.rows
: > "$rows"
fails=0; warns=0; unknowns=0

# row <area> <measure> <value> <budget text> <status: pass|warn|fail|n/a|info>
row() {
  printf '%s\t%s\t%s\t%s\t%s\n' "$1" "$2" "$3" "$4" "$5" >> "$rows"
  case $5 in fail) fails=$((fails+1)) ;; warn) warns=$((warns+1)) ;; n/a) unknowns=$((unknowns+1)) ;; esac
}
# le/lt <value> <limit> -> pass or fail; n/a when the value is not a number
le() { if num "$1"; then awk -v v="$1" -v l="$2" 'BEGIN{print (v<=l)?"pass":"fail"}'; else echo "n/a"; fi; }
lt() { if num "$1"; then awk -v v="$1" -v l="$2" 'BEGIN{print (v<l)?"pass":"fail"}'; else echo "n/a"; fi; }

profile=${PROFILE_UNDER_TEST:-$(g stack_profile)}
ram=$(g ram_total_mb)

# --- host
row host "Model" "$(g host_model)" "" info
row host "Architecture / kernel" "$(g host_arch) / $(g host_kernel)" "" info
row host "RAM / swap (MB)" "${ram:-?} / $(g swap_total_mb)" "" info
row host "Disk free (MB)" "$(g disk_free_mb)" "" info
row host "Storage" "$(g storage_type)" "" info
t0=$(g temp_start_c)
row host "CPU temperature at start (C)" "${t0:-n/a}" "< $BUDGET_TEMP_MAX_C" "$(lt "$t0" "$BUDGET_TEMP_MAX_C")"
th=$(g throttled_start)
if [ -z "$th" ]; then row host "Throttled at start" "n/a (no vcgencmd)" "0x0" n/a
elif [ "$th" = "0x0" ]; then row host "Throttled at start" "$th" "0x0" pass
else row host "Throttled at start" "$th" "0x0" fail; fi

# --- stack
sp=$(g stack_profile)
if [ -z "$sp" ]; then pst=n/a
elif [ "$sp" = "${PROFILE_UNDER_TEST:-}" ]; then pst=pass
else pst=fail; fi
row stack "Profile reported by /api/info" "${sp:-n/a}" "= ${PROFILE_UNDER_TEST:-?}" "$pst"
row stack "Image architectures" "$(g stack_image_arch)" "same as host" info
row stack "Containers running" "$(g stack_containers)" "" info

# --- idle memory
case $profile in control) lim=$BUDGET_IDLE_MB_CONTROL ;; full) lim=$BUDGET_IDLE_MB_FULL ;; *) lim=$BUDGET_IDLE_MB_STANDARD ;; esac
idle=$(g idle_mem_used_mb_avg)
row memory "Idle memory in use, 60 s average (MB)" "${idle:-n/a}" "< $lim ($profile, 1 GB board)" "$(lt "$idle" "$lim")"
row memory "Idle memory in use, 60 s peak (MB)" "$(g idle_mem_used_mb_max)" "" info

# --- web timings
for n in api_info api_devices devices library api_library_albums; do
  st=$(g "timing_${n}_status")
  [ -n "$st" ] || continue
  label=$(g "timing_${n}_path")
  if [ "$st" = "skipped" ]; then row web "$label" "skipped: $(g "timing_${n}_why")" "" info; continue; fi
  med=$(g "timing_${n}_median_ms"); p95=$(g "timing_${n}_p95_ms")
  if [ "$st" != "200" ]; then row web "$label p95 (ms)" "HTTP $st" "HTTP 200" fail; continue; fi
  row web "$label median (ms)" "$med" "" info
  # the roadmap budget names /devices; the other pages use the same number as a proposal
  row web "$label p95 (ms)" "$p95" "< $BUDGET_WEB_P95_MS" "$(le "$p95" "$BUDGET_WEB_P95_MS")"
done
peak=$(g burst_mem_peak_mb)
if num "$peak" && num "$ram"; then
  pct=$(awk -v p="$peak" -v r="$ram" 'BEGIN{printf "%d", p*100/r}')
  row web "Memory peak during 10 concurrent requests (MB, % of RAM)" "$peak ($pct%)" "< $BUDGET_BURST_PEAK_PCT%" "$(lt "$pct" "$BUDGET_BURST_PEAK_PCT")"
else
  row web "Memory peak during 10 concurrent requests (MB)" "${peak:-n/a}" "" n/a
fi

# --- stress
case $(g stress_ran) in
  yes)
    v=$(g stress_verdict)
    if [ "$v" = PASS ]; then vs=pass; elif [ -z "$v" ]; then vs=n/a; else vs=fail; fi
    row stress "Verdict of profile:stress-sqlite" "${v:-n/a}" "PASS" "$vs"
    mins=$(g stress_minutes)
    if num "$mins" && [ "${mins%.*}" -ge 1440 ]; then ds=pass; else ds=warn; fi
    row stress "Duration (minutes)" "$mins" "1440 (24 h) is the real gate" "$ds"
    row stress "database is locked (command output)" "$(g stress_locked)" "<= $BUDGET_LOCKED_MAX" "$(le "$(g stress_locked)" "$BUDGET_LOCKED_MAX")"
    row stress "Memory in use, peak during test (MB)" "$(g stress_mem_peak_mb)" "" info
    row stress "CPU temperature, max during test (C)" "$(g stress_temp_max_c)" "< $BUDGET_TEMP_MAX_C" "$(lt "$(g stress_temp_max_c)" "$BUDGET_TEMP_MAX_C")"
    te=$(g stress_throttled_end)
    if [ -z "$te" ]; then row stress "Throttled after the test" "n/a" "0x0" n/a
    elif [ "$te" = "0x0" ]; then row stress "Throttled after the test" "$te" "0x0" pass
    else row stress "Throttled after the test" "$te" "0x0" fail; fi
    ;;
  *) row stress "Stress test" "not run: $(g stress_why)" "" info ;;
esac

# --- log scan
for k in locked oom killed maxattempts refused; do
  case $k in
    locked) l='database is locked' ;; oom) l='OOM' ;; killed) l='Killed' ;;
    maxattempts) l='MaxAttemptsExceeded' ;; refused) l='Connection refused' ;;
  esac
  c=$(g "log_$k")
  row logs "\`$l\` in container logs" "${c:-n/a}" "<= $BUDGET_LOG_BAD_MAX" "$(le "$c" "$BUDGET_LOG_BAD_MAX")"
done

steps_failed=$(g steps_failed)

# ---------- report.md
{
  echo "# Hardware check report"
  echo
  echo "- Profile under test: **${profile:-unknown}**"
  echo "- Base URL: $(g base_url)"
  echo "- Run started: $(g started_at), finished: $(g finished_at)"
  echo "- Result: **$fails fail, $warns warn, $unknowns not measured**"
  [ -n "$steps_failed" ] && echo "- Steps that failed or were skipped: $steps_failed"
  echo
  echo "> The budgets come from \`budgets.conf\` and are **proposals**, not decisions. A pass means \"inside a proposed budget on this one run\", nothing more."
  echo
  echo "| Area | Measure | Value | Budget | Result |"
  echo "|---|---|---|---|---|"
  awk -F'\t' '{ s=$5; if(s=="pass")s="pass"; else if(s=="fail")s="**FAIL**"; else if(s=="warn")s="**warn**"; else if(s=="n/a")s="not measured"; else s=""; printf "| %s | %s | %s | %s | %s |\n",$1,$2,$3,$4,s }' "$rows"
  echo
  echo "Raw files in this folder: \`results.env\`, \`*.txt\`, \`*.samples\`, \`*.times\`."
} > "$md"

# ---------- report.json (strings escaped by awk)
awk -F'\t' -v prof="${profile:-}" -v f="$fails" -v w="$warns" -v u="$unknowns" -v url="$(g base_url)" '
function esc(s){ gsub(/\\/,"\\\\",s); gsub(/"/,"\\\"",s); return s }
BEGIN{ printf "{\n  \"profile\": \"%s\",\n  \"base_url\": \"%s\",\n  \"summary\": {\"fail\": %d, \"warn\": %d, \"not_measured\": %d},\n  \"rows\": [\n", esc(prof), esc(url), f, w, u }
{ if(NR>1) printf ",\n"; printf "    {\"area\": \"%s\", \"measure\": \"%s\", \"value\": \"%s\", \"budget\": \"%s\", \"result\": \"%s\"}", esc($1),esc($2),esc($3),esc($4),esc($5) }
END{ printf "\n  ]\n}\n" }' "$rows" > "$js"
rm -f "$rows"
echo "wrote $md and $js ($fails fail, $warns warn, $unknowns not measured)"
