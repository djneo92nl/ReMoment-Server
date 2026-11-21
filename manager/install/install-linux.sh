#!/usr/bin/env bash
# Installs (or updates) the ReMoment Manager on a Linux host as a systemd service. Safe to run again: it writes a file
# only when its content changed, never replaces an existing token, and restarts the service only when something changed.
# Nothing is ever printed that is secret: the token stays in its file.
#
#   sudo manager/install/install-linux.sh [options]      (see --help)
#
# Design and the API: docs/architecture/manager.md. Everything here is unverified on a real host: it was only run into a
# scratch directory (DESTDIR) by manager/test/install.test.js, with systemctl and chown never called.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE_FILE="docker-compose.prod.yml"
COMPOSE_PROJECT="remoment"
MANAGER_HOME="/var/lib/remoment-manager"
PORT="8099"
RUN_USER="root"
APP_UID=""
APP_GID=""
LAN=0
WITH_SAMBA=0
NODE_BIN=""
DRY_RUN=0
DESTDIR="${DESTDIR:-}"   # a scratch root for tests: every file goes below it, systemctl/chown/package installs are skipped

usage() {
  cat <<USAGE
Usage: sudo $0 [options]

  --project-dir DIR      the ReMoment checkout (default: $PROJECT_DIR)
  --compose-file FILE    compose file inside it (default: $COMPOSE_FILE)
  --compose-project NAME compose project name (default: $COMPOSE_PROJECT)
  --home DIR             the manager's home: token, audit log, state (default: $MANAGER_HOME)
  --port N               port on 127.0.0.1 (default: $PORT)
  --user NAME            unix user the service runs as (default: root). SMB shares and NAS mounts need root; for only
                         containers, status and upgrade any user in the docker group is enough.
  --app-uid UID          the app container's numeric user: it may read the token (needed when the service runs as root,
                         because the app reads the token from the manager's home) and owns files on NAS mounts.
                         Default: the owner of the project directory.
  --app-gid GID          group for NAS mount files (default: the same as --app-uid)
  --lan                  also listen on the network (plain HTTP, token sign-in): only on a trusted LAN
  --with-samba           install Samba if it is missing and add the include line to smb.conf (for SMB shares)
  --node PATH            the node binary (default: the one on PATH; version 18 or newer)
  --dry-run              show what would be done and change nothing
  -h, --help
Uninstall: $SCRIPT_DIR/uninstall-linux.sh
USAGE
}

say() { printf '%s\n' "$*"; }
note() { printf '  %s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
  case "$1" in
    --project-dir) PROJECT_DIR="${2:-}"; shift 2 ;;
    --compose-file) COMPOSE_FILE="${2:-}"; shift 2 ;;
    --compose-project) COMPOSE_PROJECT="${2:-}"; shift 2 ;;
    --home) MANAGER_HOME="${2:-}"; shift 2 ;;
    --port) PORT="${2:-}"; shift 2 ;;
    --user) RUN_USER="${2:-}"; shift 2 ;;
    --app-uid) APP_UID="${2:-}"; shift 2 ;;
    --app-gid) APP_GID="${2:-}"; shift 2 ;;
    --node) NODE_BIN="${2:-}"; shift 2 ;;
    --lan) LAN=1; shift ;;
    --with-samba) WITH_SAMBA=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; die "unknown option: $1" ;;
  esac
done

# Every value ends up in a unit or env file: plain characters only, so nothing can add a line or a command.
plain() { case "$2" in ''|*[!A-Za-z0-9_./-]*) die "$1 has characters this installer does not accept: '$2'" ;; esac; }
plain --project-dir "$PROJECT_DIR"; plain --compose-file "$COMPOSE_FILE"; plain --compose-project "$COMPOSE_PROJECT"
plain --home "$MANAGER_HOME"; plain --user "$RUN_USER"
case "$PORT" in ''|*[!0-9]*) die "--port must be a number" ;; esac
[ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ] || die "--port must be 1-65535"
case "$PROJECT_DIR" in /*) ;; *) die "--project-dir must be an absolute path" ;; esac
case "$MANAGER_HOME" in /*) ;; *) die "--home must be an absolute path" ;; esac
case "$APP_UID" in *[!0-9]*) die "--app-uid must be a number" ;; esac
case "$APP_GID" in *[!0-9]*) die "--app-gid must be a number" ;; esac

CHANGED=0      # something was (or would be) written in this run
LAST_WRITE=0   # the last write_file call wrote (or would write)
dest() { printf '%s%s' "$DESTDIR" "$1"; }
act() { # run a command, or only show it
  if [ "$DRY_RUN" = 1 ]; then note "would run: $*"; else "$@"; fi
}

# write_file PATH MODE: content on stdin. Replaces the file only when it differs.
write_file() {
  local target tmp
  target="$(dest "$1")"
  LAST_WRITE=0
  tmp="$(mktemp "${TMPDIR:-/tmp}/remoment-install.XXXXXX")"
  cat > "$tmp"
  if [ -f "$target" ] && cmp -s "$tmp" "$target"; then
    rm -f "$tmp"; note "unchanged: $1"; return 0
  fi
  CHANGED=1
  LAST_WRITE=1
  if [ "$DRY_RUN" = 1 ]; then rm -f "$tmp"; note "would write: $1"; return 0; fi
  mkdir -p "$(dirname "$target")"
  chmod "$2" "$tmp"
  mv "$tmp" "$target"
  note "wrote: $1"
}

# --- Checks -------------------------------------------------------------------------------------------------------
say "ReMoment Manager: installing from $PROJECT_DIR"
[ -f "$PROJECT_DIR/manager/server.js" ] || die "$PROJECT_DIR/manager/server.js is missing: is this a ReMoment checkout?"
[ -f "$PROJECT_DIR/$COMPOSE_FILE" ] || note "warning: $PROJECT_DIR/$COMPOSE_FILE does not exist yet; the manager needs it before it can show containers"
if [ -z "$NODE_BIN" ]; then NODE_BIN="$(command -v node || true)"; fi
[ -n "$NODE_BIN" ] && [ -x "$NODE_BIN" ] || die "node is not installed (version 18 or newer; apt install nodejs)"
plain --node "$NODE_BIN"
NODE_MAJOR="$("$NODE_BIN" -p 'process.versions.node.split(".")[0]')"
[ "$NODE_MAJOR" -ge 18 ] || die "node $NODE_MAJOR is too old: the manager needs 18 or newer"

if [ -z "$DESTDIR" ] && [ "$DRY_RUN" = 0 ]; then
  [ "$(id -u)" = 0 ] || die "run as root (sudo): the installer writes /etc and /var/lib and manages a service"
  command -v systemctl >/dev/null 2>&1 || die "this host has no systemd. The manager can still be started by hand: node $PROJECT_DIR/manager/server.js (see manager/install/README.md)"
  command -v docker >/dev/null 2>&1 || note "warning: docker is not installed; the manager shows no containers until it is"
  if [ "$RUN_USER" != root ]; then
    id "$RUN_USER" >/dev/null 2>&1 || die "user $RUN_USER does not exist"
    id -nG "$RUN_USER" | tr ' ' '\n' | grep -qx docker || die "user $RUN_USER is not in the docker group (usermod -aG docker $RUN_USER), or use --user root"
  fi
fi
if [ -z "$APP_UID" ] && [ -z "$DESTDIR" ]; then
  APP_UID="$(stat -c %u "$PROJECT_DIR" 2>/dev/null || stat -f %u "$PROJECT_DIR" 2>/dev/null || true)"
  # The app container's user is almost never root; a root-owned checkout gives no uid to hand the token to.
  if [ "$APP_UID" = 0 ]; then APP_UID=""; fi
fi
if [ -z "$APP_GID" ]; then APP_GID="$APP_UID"; fi
HAND_OVER=0
if [ "$RUN_USER" = root ] && [ -n "$APP_UID" ]; then HAND_OVER=1; fi

# --- Home and token ------------------------------------------------------------------------------------------------
HOME_MODE=700
if [ "$HAND_OVER" = 1 ]; then HOME_MODE=755; fi
TOKEN_FILE="$MANAGER_HOME/token"
say "Manager home and token"
if [ "$DRY_RUN" = 1 ]; then
  note "would create $MANAGER_HOME (mode $HOME_MODE)"
else
  mkdir -p "$(dest "$MANAGER_HOME")"
  chmod "$HOME_MODE" "$(dest "$MANAGER_HOME")"
  if [ -z "$DESTDIR" ] && [ "$RUN_USER" != root ]; then chown "$RUN_USER" "$MANAGER_HOME"; fi
fi
if [ -s "$(dest "$TOKEN_FILE")" ]; then
  note "token exists, kept: $TOKEN_FILE"
elif [ "$DRY_RUN" = 1 ]; then
  note "would create the token $TOKEN_FILE (mode 600, 256 random bits)"
else
  # The token never touches a command line or the output: straight from the random source into a 0600 file.
  ( umask 077; od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > "$(dest "$TOKEN_FILE")"; printf '\n' >> "$(dest "$TOKEN_FILE")" )
  chmod 600 "$(dest "$TOKEN_FILE")"
  note "created the token $TOKEN_FILE (mode 600)"
fi
if [ -z "$DESTDIR" ] && [ "$DRY_RUN" = 0 ] && [ -f "$TOKEN_FILE" ]; then
  if [ "$HAND_OVER" = 1 ]; then chown "$APP_UID" "$TOKEN_FILE"; elif [ "$RUN_USER" != root ]; then chown "$RUN_USER" "$TOKEN_FILE"; fi
fi

# --- Settings files and the unit ----------------------------------------------------------------------------------
say "Settings and service unit"
{
  echo "# Written by manager/install/install-linux.sh: replaced on every run. Put your own settings in local.env."
  echo "REMOMENT_PROJECT_DIR=$PROJECT_DIR"
  echo "REMOMENT_COMPOSE_FILE=$COMPOSE_FILE"
  echo "REMOMENT_COMPOSE_PROJECT=$COMPOSE_PROJECT"
  echo "REMOMENT_MANAGER_HOME=$MANAGER_HOME"
  echo "REMOMENT_MANAGER_PORT=$PORT"
  if [ "$LAN" = 1 ]; then echo "REMOMENT_MANAGER_BIND=0.0.0.0"; else echo "REMOMENT_MANAGER_BIND=127.0.0.1"; fi
  if [ "$HAND_OVER" = 1 ]; then echo "REMOMENT_MANAGER_TOKEN_UID=$APP_UID"; fi
  if [ "$RUN_USER" = root ] && [ -n "$APP_UID" ]; then echo "REMOMENT_MOUNT_UID=$APP_UID"; echo "REMOMENT_MOUNT_GID=${APP_GID:-$APP_UID}"; fi
} | write_file /etc/remoment-manager/manager.env 644

if [ ! -e "$(dest /etc/remoment-manager/local.env)" ]; then
  cat <<'LOCAL' | write_file /etc/remoment-manager/local.env 644
# Your settings for the ReMoment Manager. This file is never overwritten by the installer. KEY=value, no quotes.
# After a change: systemctl restart remoment-manager
#REMOMENT_SMB_LIBRARY_DIR=/srv/library
#REMOMENT_SMB_EXPORTS_DIR=/srv/exports
#REMOMENT_SMB_INBOX_DIR=/srv/inbox
#REMOMENT_SMB_FORCE_USER=sail
#REMOMENT_MOUNT_BASE=/mnt/remoment
LOCAL
else
  note "kept: /etc/remoment-manager/local.env"
fi

sed -e "s|@PROJECT_DIR@|$PROJECT_DIR|g" -e "s|@NODE@|$NODE_BIN|g" -e "s|@USER@|$RUN_USER|g" "$SCRIPT_DIR/remoment-manager.service" \
  | write_file /etc/systemd/system/remoment-manager.service 644
UNIT_CHANGED=$LAST_WRITE

# The CLI on the PATH, and the variables it needs in every login shell.
if [ "$DRY_RUN" = 1 ]; then
  note "would link /usr/local/bin/remoment-manager"
else
  mkdir -p "$(dest /usr/local/bin)"
  ln -sf "$PROJECT_DIR/manager/bin/remoment-manager" "$(dest /usr/local/bin/remoment-manager)"
  note "linked: /usr/local/bin/remoment-manager"
fi
{
  echo "# Written by manager/install/install-linux.sh"
  echo "export REMOMENT_MANAGER_HOME=$MANAGER_HOME"
  echo "export REMOMENT_MANAGER_PORT=$PORT"
} | write_file /etc/profile.d/remoment-manager.sh 644

# --- Samba (only with --with-samba) -------------------------------------------------------------------------------
if [ "$WITH_SAMBA" = 1 ]; then
  say "Samba"
  [ "$RUN_USER" = root ] || die "--with-samba needs --user root: the manager writes /etc/samba and creates the share user"
  SMB_CONF=/etc/samba/smb.conf
  FRAGMENT=/etc/samba/remoment.conf
  if [ -z "$DESTDIR" ] && [ "$DRY_RUN" = 0 ] && ! command -v smbd >/dev/null 2>&1; then
    if command -v apt-get >/dev/null 2>&1; then DEBIAN_FRONTEND=noninteractive apt-get install -y samba
    elif command -v dnf >/dev/null 2>&1; then dnf install -y samba
    else die "no apt-get or dnf: install Samba yourself, then run this again"; fi
  else
    note "Samba is present (or this is a dry run): not installing"
  fi
  # The manager owns the fragment; an empty one is fine and is filled when the first share is switched on.
  if [ ! -e "$(dest $FRAGMENT)" ]; then printf '# Managed by the ReMoment Manager\n' | write_file "$FRAGMENT" 644; else note "kept: $FRAGMENT"; fi
  if [ -f "$(dest $SMB_CONF)" ]; then
    if grep -Eq "^[[:space:]]*include[[:space:]]*=[[:space:]]*$FRAGMENT[[:space:]]*$" "$(dest $SMB_CONF)"; then
      note "smb.conf already includes $FRAGMENT"
    elif [ "$DRY_RUN" = 1 ]; then
      note "would append 'include = $FRAGMENT' to $SMB_CONF (backup first)"
    else
      [ -e "$(dest $SMB_CONF).remoment-backup" ] || cp -p "$(dest $SMB_CONF)" "$(dest $SMB_CONF).remoment-backup"
      # At the END of the file: the fragment has its own [global] and share sections, so it must not split smb.conf's own [global].
      printf '\n# ReMoment Manager shares\ninclude = %s\n' "$FRAGMENT" >> "$(dest $SMB_CONF)"
      note "appended the include line to $SMB_CONF (backup: $SMB_CONF.remoment-backup)"
    fi
  else
    note "warning: $SMB_CONF does not exist; add 'include = $FRAGMENT' to it when Samba is set up"
  fi
fi

# --- Start ---------------------------------------------------------------------------------------------------------
say "Service"
if [ -n "$DESTDIR" ] || [ "$DRY_RUN" = 1 ]; then
  note "systemd steps skipped (scratch root or dry run): daemon-reload, enable, restart"
else
  if [ "$UNIT_CHANGED" = 1 ]; then systemctl daemon-reload; fi
  systemctl enable remoment-manager >/dev/null 2>&1 || systemctl enable remoment-manager
  if systemctl is-active --quiet remoment-manager; then
    if [ "$CHANGED" = 1 ]; then systemctl restart remoment-manager; note "restarted (settings or unit changed)"; else note "running, nothing changed"; fi
  else
    systemctl start remoment-manager; note "started"
  fi
  if command -v curl >/dev/null 2>&1; then
    ok=0
    for _ in 1 2 3 4 5 6 7 8 9 10; do
      if curl -fsS "http://127.0.0.1:$PORT/health" >/dev/null 2>&1; then ok=1; break; fi
      sleep 1
    done
    if [ "$ok" = 1 ]; then note "answers on http://127.0.0.1:$PORT/health"; else note "warning: no answer yet on port $PORT: journalctl -u remoment-manager"; fi
  fi
fi

say ""
WHO="$RUN_USER"
if [ "$HAND_OVER" = 1 ]; then WHO="root and uid $APP_UID"; fi
say "Done. The token is in $TOKEN_FILE (readable by $WHO only); it is never printed."
say "  CLI:       remoment-manager status        (log in again for the profile.d variables)"
if [ "$LAN" = 1 ]; then
  say "  Web page:  http://<this host>:$PORT/    plain HTTP on the network: a trusted LAN only"
else
  say "  Web page:  http://127.0.0.1:$PORT/       this host only (--lan opens it to the network)"
fi
say "  App:       in the app's .env: MANAGER_HOME=$MANAGER_HOME so Settings > Manager can read the token"
