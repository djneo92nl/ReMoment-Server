#!/usr/bin/env bash
# Removes the ReMoment Manager service installed by install-linux.sh. Safe to run again and on a host where it was never
# installed. It keeps the manager's home (token, audit log, mount and share state) unless --purge, and never touches what
# the manager configured for others: the Samba fragment and include line, NAS mount units and their credentials stay and
# keep working on their own (remove them first with `remoment-manager mount remove <name>` / `smb disable <share>`).
set -euo pipefail

MANAGER_HOME="/var/lib/remoment-manager"
PURGE=0
DRY_RUN=0
DESTDIR="${DESTDIR:-}"

usage() {
  cat <<USAGE
Usage: sudo $0 [options]
  --home DIR   the manager's home (default: $MANAGER_HOME)
  --purge      also delete the home (token, audit log, state) and the settings files (never the NAS credentials)
  --dry-run    show what would be done and change nothing
USAGE
}
say() { printf '%s\n' "$*"; }
note() { printf '  %s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }
while [ $# -gt 0 ]; do
  case "$1" in
    --home) MANAGER_HOME="${2:-}"; shift 2 ;;
    --purge) PURGE=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; die "unknown option: $1" ;;
  esac
done
case "$MANAGER_HOME" in ''|*[!A-Za-z0-9_./-]*|[!/]*) die "--home must be a plain absolute path" ;; esac
case "$MANAGER_HOME" in /|/etc|/usr|/var|/home|/root|/bin|/lib|/opt|/srv|/tmp) die "refusing to purge $MANAGER_HOME" ;; esac

dest() { printf '%s%s' "$DESTDIR" "$1"; }
remove() { # remove PATH: a file, link or (with -r) a directory, only when it exists
  local flag="-f"; [ "$1" = "-r" ] && { flag="-rf"; shift; }
  if [ -e "$(dest "$1")" ] || [ -L "$(dest "$1")" ]; then
    if [ "$DRY_RUN" = 1 ]; then note "would remove: $1"; else rm $flag "$(dest "$1")"; note "removed: $1"; fi
  else
    note "not there: $1"
  fi
}

say "ReMoment Manager: uninstalling"
if [ -z "$DESTDIR" ] && [ "$DRY_RUN" = 0 ]; then
  [ "$(id -u)" = 0 ] || die "run as root (sudo)"
  if command -v systemctl >/dev/null 2>&1; then
    systemctl disable --now remoment-manager 2>/dev/null && note "stopped and disabled the service" || note "service was not running"
  fi
else
  note "systemd steps skipped (scratch root or dry run): disable --now"
fi
remove /etc/systemd/system/remoment-manager.service
if [ -z "$DESTDIR" ] && [ "$DRY_RUN" = 0 ] && command -v systemctl >/dev/null 2>&1; then systemctl daemon-reload || true; fi
remove /usr/local/bin/remoment-manager
remove /etc/profile.d/remoment-manager.sh
remove /etc/remoment-manager/manager.env
if [ "$PURGE" = 1 ]; then
  remove /etc/remoment-manager/local.env
  remove -r "$MANAGER_HOME"
  # The credentials of NAS mounts stay: their units still point at them and would stop mounting without them.
  note "kept: /etc/remoment-manager/credentials (NAS mounts use it)"
else
  note "kept: $MANAGER_HOME (token, audit log, state) and /etc/remoment-manager/local.env; --purge deletes them"
  if [ -f "$(dest "$MANAGER_HOME/mounts.json")" ] && grep -q '"host"' "$(dest "$MANAGER_HOME/mounts.json")"; then
    note "NAS mounts configured through the manager keep working (their units are in /etc/systemd/system); remove them by hand, or reinstall the manager and use 'remoment-manager mount remove'"
  fi
fi
say "Done."
