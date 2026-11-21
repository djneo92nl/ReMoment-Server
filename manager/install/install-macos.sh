#!/usr/bin/env bash
# Installs (or updates) the ReMoment Manager as a launchd agent on a Mac, for the current user (no sudo). Safe to run
# again. The token is made by the manager itself on first start (~/.remoment-manager/token, mode 600) and never printed.
# Unverified on a real Mac: it was only rendered into a scratch directory (DESTDIR) and linted with plutil.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE_FILE="docker-compose.prod.yml"
COMPOSE_PROJECT="remoment"
HOME_DIR="${HOME:-}/.remoment-manager"
PORT="8099"
NODE_BIN=""
DRY_RUN=0
DESTDIR="${DESTDIR:-}"   # scratch root for tests: the agent is not loaded, no service command runs
LABEL="nl.remoment.manager"

say() { printf '%s\n' "$*"; }
note() { printf '  %s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }
usage() {
  cat <<USAGE
Usage: bash $0 [options]
  --project-dir DIR      the ReMoment checkout (default: $PROJECT_DIR)
  --compose-file FILE    default: $COMPOSE_FILE
  --compose-project NAME default: $COMPOSE_PROJECT
  --home DIR             the manager's home (default: $HOME_DIR)
  --port N               port on 127.0.0.1 (default: $PORT)
  --node PATH            node binary (default: the one on PATH, 18 or newer)
  --dry-run              show what would be done and change nothing
Uninstall: $SCRIPT_DIR/uninstall-macos.sh
USAGE
}
while [ $# -gt 0 ]; do
  case "$1" in
    --project-dir) PROJECT_DIR="${2:-}"; shift 2 ;;
    --compose-file) COMPOSE_FILE="${2:-}"; shift 2 ;;
    --compose-project) COMPOSE_PROJECT="${2:-}"; shift 2 ;;
    --home) HOME_DIR="${2:-}"; shift 2 ;;
    --port) PORT="${2:-}"; shift 2 ;;
    --node) NODE_BIN="${2:-}"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; die "unknown option: $1" ;;
  esac
done

# The values go into an XML file: plain characters only (no & < > quotes).
plain() { case "$2" in ''|*[!A-Za-z0-9_./-]*) die "$1 has characters this installer does not accept: '$2'" ;; esac; }
plain --project-dir "$PROJECT_DIR"; plain --compose-file "$COMPOSE_FILE"; plain --compose-project "$COMPOSE_PROJECT"; plain --home "$HOME_DIR"
case "$PORT" in ''|*[!0-9]*) die "--port must be a number" ;; esac
case "$PROJECT_DIR" in /*) ;; *) die "--project-dir must be an absolute path" ;; esac
case "$HOME_DIR" in /*) ;; *) die "--home must be an absolute path" ;; esac
[ -f "$PROJECT_DIR/manager/server.js" ] || die "$PROJECT_DIR/manager/server.js is missing"
if [ -z "$NODE_BIN" ]; then NODE_BIN="$(command -v node || true)"; fi
[ -n "$NODE_BIN" ] && [ -x "$NODE_BIN" ] || die "node is not installed (version 18 or newer)"
plain --node "$NODE_BIN"
NODE_MAJOR="$("$NODE_BIN" -p 'process.versions.node.split(".")[0]')"
[ "$NODE_MAJOR" -ge 18 ] || die "node $NODE_MAJOR is too old: the manager needs 18 or newer"

AGENT_DIR="$DESTDIR${HOME:-}/Library/LaunchAgents"
LOG_DIR="${HOME:-}/Library/Logs"
PLIST="$AGENT_DIR/$LABEL.plist"
plain "the LaunchAgents path" "$AGENT_DIR"; plain "the logs path" "$LOG_DIR"

say "ReMoment Manager: installing the launchd agent from $PROJECT_DIR"
RENDERED="$(sed -e "s|@PROJECT_DIR@|$PROJECT_DIR|g" -e "s|@NODE@|$NODE_BIN|g" -e "s|@COMPOSE_FILE@|$COMPOSE_FILE|g" \
  -e "s|@COMPOSE_PROJECT@|$COMPOSE_PROJECT|g" -e "s|@HOME_DIR@|$HOME_DIR|g" -e "s|@PORT@|$PORT|g" -e "s|@LOG@|$LOG_DIR/remoment-manager.log|g" \
  "$SCRIPT_DIR/nl.remoment.manager.plist")"
if command -v plutil >/dev/null 2>&1; then
  printf '%s\n' "$RENDERED" | plutil -lint - >/dev/null || die "the rendered plist is not valid"
fi

CHANGED=0
if [ -f "$PLIST" ] && [ "$(cat "$PLIST")" = "$RENDERED" ]; then
  note "unchanged: $PLIST"
elif [ "$DRY_RUN" = 1 ]; then
  note "would write: $PLIST"
else
  mkdir -p "$AGENT_DIR"
  printf '%s\n' "$RENDERED" > "$PLIST.new"
  chmod 644 "$PLIST.new"
  mv "$PLIST.new" "$PLIST"
  CHANGED=1
  note "wrote: $PLIST"
fi

if [ "$DRY_RUN" = 1 ] || [ -n "$DESTDIR" ]; then
  note "agent loading skipped (scratch root or dry run)"
else
  mkdir -p "$LOG_DIR"
  DOMAIN="gui/$(id -u)"
  if launchctl print "$DOMAIN/$LABEL" >/dev/null 2>&1; then
    if [ "$CHANGED" = 1 ]; then
      launchctl bootout "$DOMAIN/$LABEL" || true
      launchctl bootstrap "$DOMAIN" "$PLIST"
      note "reloaded (the agent changed)"
    else
      note "already loaded, nothing changed"
    fi
  else
    launchctl bootstrap "$DOMAIN" "$PLIST"
    note "loaded"
  fi
fi

say ""
say "Done. The manager makes its token on first start: $HOME_DIR/token (mode 600), never printed."
say "  CLI:       REMOMENT_MANAGER_HOME=$HOME_DIR REMOMENT_MANAGER_PORT=$PORT $PROJECT_DIR/manager/bin/remoment-manager status"
say "  Web page:  http://127.0.0.1:$PORT/"
say "  Log:       $LOG_DIR/remoment-manager.log"
