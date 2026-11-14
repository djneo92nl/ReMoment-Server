# Schema rehearsal (workstream 14.3)

Proof tooling for the migration squash ([schema-plan.md](../../docs/architecture/schema-plan.md)): build the schema of the
migrations in **throw-away databases**, dump it **normalised**, and diff two dumps. After the squash, `check` shows that the
consolidated migration set builds the same schema, on MariaDB and on SQLite, as the 62 migrations did.

Lives under `scripts/schema/` (not `docs/architecture/spikes/`): it is a tool the owner runs again at squash time, not the record of a spike.

```bash
scripts/schema/schema.sh dump all [--out DIR]   # mariadb + sqlite, default DIR scripts/schema/out (git-ignored)
scripts/schema/schema.sh dump mariadb|sqlite
scripts/schema/schema.sh check                  # dump all, diff against baseline/ (exit 1 on any difference)
scripts/schema/schema.sh diff A.txt B.txt
scripts/schema/schema.sh crosscheck             # Eloquent models vs the migrated schema (columns, tables)
```

Needs only docker (images `mariadb:11.4` and a PHP 8.4 image with `pdo_mysql` and `pdo_sqlite`: `remoment/app-dev` by default (`docker compose build`),
override with `SCHEMA_PHP_IMAGE`; `SCHEMA_DB_IMAGE` for the database image).

## What it touches

- A MariaDB container, network and volume named `remoment-schema-{db,net,vol}-<time>-<pid>`, created by the run and removed by
  its `trap` (reverse order, only what the run recorded). No published ports, no existing container, volume or database.
- `php artisan migrate --force` runs in a one-off container with the project mounted **read-only**, an empty `.env` mounted over
  the real one (so the owner's `.env` is never read), `storage` and `bootstrap/cache` on tmpfs, and all settings passed as `-e`
  variables. SQLite is a file under the container's `/tmp`. Nothing is written to the checkout except the dump you ask for.
- `scripts/schema/dump.php` only reads `information_schema` / `PRAGMA`s.

## What a dump holds

Per table (sorted): columns sorted by **name** (column order is not compared, so `->after()` and a different layout do not show),
with type, NULL, default, collation, `auto_increment`; indexes (name, unique, type, columns); foreign keys with `ON DELETE` /
`ON UPDATE`; MariaDB `CHECK`s (Laravel's `json()` is `longtext` + `json_valid`); on SQLite the enum `CHECK`s. No data, no
auto-increment counters, no timestamps. The `migrations` table is included structurally, its rows are not.

Differences between the two dumps are expected and are not bugs: MariaDB lists `PRIMARY` and the indexes InnoDB creates for
foreign keys (129 vs 82 indexes), SQLite types are affinity names, SQLite has no JSON `CHECK`.

## Baselines

`baseline/mariadb.schema.txt` and `baseline/sqlite.schema.txt` are the dumps of the 62 pre-squash migrations (2026-11-01):
**44 tables (43 + `migrations`), 419 columns, 33 foreign keys, 129 indexes on MariaDB, 82 on SQLite**. Schema only, no data and no
secrets (the word "password" appears only as a column name). Never regenerate them to make `check` pass after the squash; they
are the thing the squash is proven against. Additive migrations that land before the squash (a new table, column or index) are added to the baseline in the same commit as the migration, or the CI job fails: 2026-11-14 added `exports`, `play_tags` and the `plays` played_at index (46 tables incl. `migrations`, 454 columns, 137 MariaDB indexes). Regenerate only when a pre-squash fix from the plan lands (then the fix and the new
baseline go in one commit).

## Comparing with a live database (read-only, owner runs it)

The dump of a running database is a few `SELECT`s on `information_schema`. In the Sail app container:

```bash
docker compose exec -e SCHEMA_DRIVER=mysql -e DB_HOST=mariadb -e DB_PORT=3306 -e DB_DATABASE=remoment \
  -e DB_USERNAME=remoment -e DB_PASSWORD="$DB_PASSWORD" laravel.test php scripts/schema/dump.php > prod-mariadb.txt
scripts/schema/schema.sh diff scripts/schema/baseline/mariadb.schema.txt prod-mariadb.txt
```

Do this against production and dev **before** the squash: production may carry drift the migrations do not describe (the
SQLite-to-MariaDB move, hand-made indexes). The squash proof assumes production equals the baseline.

## In CI

The `mariadb-migrations` job of `.github/workflows/laravel.yml` runs on every push and pull request to `main`: a `mariadb:11.4`
service container (health-checked), `php artisan migrate --force` on the fresh database, then `php scripts/schema/dump.php`
diffed against `baseline/mariadb.schema.txt`. It runs `dump.php` directly on the runner, so it needs no docker-in-docker and
not `schema.sh`; a schema that drifts from the baseline fails the job. SQLite stays the database of the test job; the SQLite
baseline is not checked in CI (use `schema.sh check` locally). The workflow has not been run on GitHub yet; the same
commands were run against a throw-away local MariaDB 11.4 container (identical to the baseline).
