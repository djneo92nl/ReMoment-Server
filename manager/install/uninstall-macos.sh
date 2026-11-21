#!/usr/bin/env bash
# Removes the ReMoment Manager's launchd agent for the current user. Safe to run again. Keeps ~/.remoment-manager
# (token, audit log) unless --purge.
set -euo pipefail

HOME_DIR="${HOME:-}/.remoment-manager"
PURGE=0
DRY_RUN=0
DESTDIR="${DESTDIR:-}"
LABEL="nl.remoment.manager"

say() { printf '%s\n' "$*"; }
note() { printf '  %s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }
while [ $# -gt 0 ]; do
  case "$1" in
    --home) HOME_DIR="${2:-}"; shift 2 ;;
    --purge) PURGE=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) printf 'Usage: bash %s [--home DIR] [--purge] [--dry-run]\n' "$0"; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done
case "$HOME_DIR" in ''|*[!A-Za-z0-9_./-]*|[!/]*) die "--home must be a plain absolute path" ;; esac
case "$HOME_DIR" in /|/Users|/Users/|/usr|/var|/etc|/Library|/Applications) die "refusing to purge $HOME_DIR" ;; esac

PLIST="$DESTDIR${HOME:-}/Library/LaunchAgents/$LABEL.plist"
say "ReMoment Manager: uninstalling the launchd agent"
if [ "$DRY_RUN" = 1 ] || [ -n "$DESTDIR" ]; then
  note "agent unloading skipped (scratch root or dry run)"
else
  if launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null; then note "stopped and unloaded"; else note "was not loaded"; fi
fi
if [ -e "$PLIST" ]; then
  if [ "$DRY_RUN" = 1 ]; then note "would remove: $PLIST"; else rm -f "$PLIST"; note "removed: $PLIST"; fi
else
  note "not there: $PLIST"
fi
if [ "$PURGE" = 1 ]; then
  if [ -e "$DESTDIR$HOME_DIR" ]; then
    if [ "$DRY_RUN" = 1 ]; then note "would remove: $HOME_DIR"; else rm -rf "${DESTDIR:?}$HOME_DIR"; note "removed: $HOME_DIR"; fi
  else
    note "not there: $HOME_DIR"
  fi
else
  note "kept: $HOME_DIR (token, audit log); --purge deletes it"
fi
say "Done."
