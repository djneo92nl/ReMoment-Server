#!/usr/bin/env bash
# Schema rehearsal for the migration squash (workstream 14.3): build the schema of the CURRENT migrations in throw-away
# databases and dump it normalised, or compare two dumps. See README.md. Needs only docker; touches no .env, no running
# container and no existing database.
#
#   scripts/schema/schema.sh dump all|mariadb|sqlite [--out DIR]   (default DIR: scripts/schema/out)
#   scripts/schema/schema.sh check                                  dump all to out/, diff against baseline/
#   scripts/schema/schema.sh diff A.txt B.txt
#   scripts/schema/schema.sh crosscheck                             models vs the migrated schema (columns, tables)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HERE="$ROOT/scripts/schema"
PHP_IMAGE="${SCHEMA_PHP_IMAGE:-sail-8.4/app:latest}"   # any image with php 8.4, pdo_mysql, pdo_sqlite
DB_IMAGE="${SCHEMA_DB_IMAGE:-mariadb:11.4}"
SUFFIX="$(date +%s)-$$"
NET="remoment-schema-net-$SUFFIX"
DB="remoment-schema-db-$SUFFIX"
VOL="remoment-schema-vol-$SUFFIX"
STARTED=()   # what this run created; cleanup removes only these

cleanup() {
  local i item
  for ((i = ${#STARTED[@]} - 1; i >= 0; i--)); do   # reverse: the container before its network and volume
    item="${STARTED[$i]}"
    case "$item" in
      container:*) docker rm -f "${item#container:}" >/dev/null 2>&1 || true ;;
      network:*) docker network rm "${item#network:}" >/dev/null 2>&1 || true ;;
      volume:*) docker volume rm "${item#volume:}" >/dev/null 2>&1 || true ;;
    esac
  done
}
trap cleanup EXIT

# Runs a command in a throw-away PHP container: the project read-only, an empty .env, writable tmpfs for storage and
# bootstrap/cache. Extra docker args come first, then the shell snippet.
php_run() {
  local extra=("$@"); local script="${extra[${#extra[@]}-1]}"; unset 'extra[${#extra[@]}-1]'
  docker run --rm "${extra[@]}" \
    -v "$ROOT":/app:ro -v /dev/null:/app/.env:ro \
    --tmpfs /app/storage:rw --tmpfs /app/bootstrap/cache:rw \
    -e APP_ENV=testing -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e LOG_CHANNEL=stderr \
    -w /app --entrypoint sh "$PHP_IMAGE" -c "mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && $script"
}

dump_sqlite() {
  echo "== SQLite: migrate on a fresh file, dump" >&2
  php_run -e DB_CONNECTION=sqlite -e DB_DATABASE=/tmp/schema.sqlite -e SCHEMA_DRIVER=sqlite \
    "php artisan migrate --force >&2 && php scripts/schema/dump.php" > "$1"
}

dump_mariadb() {
  echo "== MariaDB ($DB_IMAGE): temporary container $DB, volume $VOL" >&2
  docker network create "$NET" >/dev/null; STARTED+=("network:$NET")
  docker volume create "$VOL" >/dev/null; STARTED+=("volume:$VOL")
  docker run -d --name "$DB" --network "$NET" -v "$VOL":/var/lib/mysql \
    -e MARIADB_ROOT_PASSWORD=schema -e MARIADB_DATABASE=remoment "$DB_IMAGE" >/dev/null; STARTED+=("container:$DB")
  for _ in $(seq 1 60); do
    docker exec "$DB" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break
    sleep 2
  done
  docker exec "$DB" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 || { echo "MariaDB did not start" >&2; exit 1; }
  php_run --network "$NET" -e DB_CONNECTION=mariadb -e DB_HOST="$DB" -e DB_PORT=3306 -e DB_DATABASE=remoment \
    -e DB_USERNAME=root -e DB_PASSWORD=schema -e SCHEMA_DRIVER=mysql \
    "php artisan migrate --force >&2 && php scripts/schema/dump.php" > "$1"
}

summary() {
  awk -v f="$1" '/^TABLE /{t++} /^  col /{c++} /^  idx /{i++} /^  fk /{k++} END{printf "%s: %d tables, %d columns, %d indexes, %d foreign keys\n", f, t, c, i, k}' "$1"
}

cmd="${1:-}"; shift || true
case "$cmd" in
  dump)
    what="${1:-all}"; shift || true
    out="$HERE/out"
    [ "${1:-}" = "--out" ] && out="$2"
    mkdir -p "$out"
    case "$what" in
      sqlite|all) dump_sqlite "$out/sqlite.schema.txt"; summary "$out/sqlite.schema.txt" ;;
    esac
    case "$what" in
      mariadb|all) dump_mariadb "$out/mariadb.schema.txt"; summary "$out/mariadb.schema.txt" ;;
    esac
    ;;
  crosscheck)
    php_run -e DB_CONNECTION=sqlite -e DB_DATABASE=/tmp/schema.sqlite \
      "php artisan migrate --force >/dev/null && php scripts/schema/crosscheck.php"
    ;;
  diff)
    diff -u "$1" "$2" && echo "identical"
    ;;
  check)
    "$0" dump all --out "$HERE/out"
    rc=0
    for d in mariadb sqlite; do
      echo "== $d vs baseline" >&2
      diff -u "$HERE/baseline/$d.schema.txt" "$HERE/out/$d.schema.txt" && echo "$d: identical" || rc=1
    done
    exit $rc
    ;;
  *)
    sed -n '2,14p' "$0"; exit 2
    ;;
esac
