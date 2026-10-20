# Similar tracks (plan, nothing built)

"Tracks like this one", radio and "mixes well" from the Discogs-EffNet embeddings the Essentia sidecar already computes for each analysed file (`docker/analysis/app.py` runs `discogs-effnet-bs64-1` and uses its output for the mood, danceability, voice and genre heads, then drops it). This page records what the sqlite-vec spike of 2026-10-20 found, so the build starts from measured facts.

## Decision

- Keep the embedding (one 1280-number vector per file, the mean over the track's patches) in the main database as the **source of truth**, a BLOB per file.
- Search it with **sqlite-vec** (`vec0`, exact scan) in a **separate SQLite file** (for example `storage/app/vectors.sqlite`), rebuilt from the BLOBs. It is a derived cache, so losing it costs a rebuild, not data.
- Keep a plain-PHP scan as the fallback for installs where the extension is not available (a Pi without a build of it): fine for a few thousand tracks, not for tens of thousands.

## What the spike measured

Inside the server container (Ubuntu 24.04, aarch64, PHP 8.4.26, SQLite 3.45.1 with `ENABLE_LOAD_EXTENSION`), sqlite-vec v0.1.9 compiled from its amalgamation with the container's gcc (`-DSQLITE_VEC_OMIT_FS`), random unit vectors of 1280 numbers, `k = 20`, a file database:

| Rows | float32, cosine | float32, L2 | int8, cosine | plain PHP scan (int8) |
|-----:|----------------:|------------:|-------------:|----------------------:|
| 2,000 | 3 ms | 2 ms | 2 ms | not measured |
| 20,000 | 33 ms | 24 ms | 18 ms | 0.95 s |
| 50,000 | 89 ms | 65 ms | 47 ms | 2.4 s |

- The extension is about 27 times faster than the PHP scan at 50k tracks (about 50 times with int8). Inserts took 2 s per 20,000 rows.
- Disk: about 5 kB per track as float32 (256 MB for 50k tracks), 1.3 kB as int8 (64 MB).
- Correctness: the nearest neighbour by cosine was the same track as an exact plain-PHP scan, with the same similarity value.
- The plain-PHP scan was timed on 3,000 rows and scaled; on the Mac host it was about twice as fast as in the container.

## What the build has to do differently from what I assumed

1. **Laravel's connection cannot load it.** `SQLiteConnector` returns a plain `PDO`, and `loadExtension()` exists only on `Pdo\Sqlite` (PHP 8.4). The index needs its own connection: `new Pdo\Sqlite('sqlite:'.$path)` then `loadExtension($absolutePath)`. An absolute path works; `sqlite3.extension_dir` is not needed.
2. **Bind vectors as BLOBs.** PDO binds strings as TEXT and sqlite-vec then parses them as JSON ("Input does not start with '['"). Use `bindValue($i, $packed, PDO::PARAM_LOB)`; floats are `pack('g*', ...)`, int8 `pack('c*', ...)` with `vec_int8(?)` in the SQL.
3. **Do not put `vec0` tables in the main database.** A file with `vec0` tables opens and works without the extension, except that touching a `vec0` table fails with `no such module: vec0`. `migrate:fresh` or a backup tool on a machine without the extension would hit that.
4. **Syntax that worked:** `create virtual table v using vec0(embedding float[1280] distance_metric=cosine)`, `insert into v(rowid, embedding) values (?, ?)` (rowid = file id), `select rowid, distance from v where embedding match ? and k = 20`; cosine similarity is `1 - distance`.
5. **Packaging:** the amalgamation (`sqlite-vec-0.1.9-amalgamation.tar.gz`, 53,872 bytes) needs `sqlite3.h` and `sqlite3ext.h`, which the images lack (install `libsqlite3-dev` in a build stage, matching the runtime SQLite). The release also has prebuilt `loadable-linux-aarch64` and `loadable-linux-x86_64` libraries. The vendor's `checksums.txt` lists the prebuilt files but **not the amalgamation**, so a Dockerfile must pin a hash it computed itself (the tarball above hashed to `3acd67cb4aff080c7050926fd3cf8227905fe5b7ee3829d8ee5024ab1283cf61`; not verified against anything published).
6. The source contains no network or process calls; its one `fopen` is the `.npy` reader, removed by `SQLITE_VEC_OMIT_FS`.

## Not verified

- **x86_64 and 32-bit ARM.** Only aarch64 was built and run. A prebuilt x86_64 library exists; there is no Linux armv7 build in the release (only Android), so a Pi 2/3 on a 32-bit OS would compile it from source: expected to work as plain C, not tried.
- **Real embeddings.** The test used random vectors, which have no structure and tiny gaps between neighbours, so int8 ranked differently from float at 2,000 rows (the same top hit at 20k and 50k). Whether int8 keeps the order for real Discogs-EffNet vectors needs a check on real analysed tracks; float32 is the safe default.
- **Raspberry Pi speed.** All timings are from an Apple Silicon machine through Docker. A Pi will be several times slower; not measured.
- **The sidecar side.** Returning the mean embedding from `/analyze`, the backlog cost of re-analysing existing files, and whether `discogs_multi_embeddings` (contrastive, a separate model) ranks better than the genre-trained embedding the sidecar runs today.
- sqlite-vec is pre-v1 ("expect breaking changes"); pin the version.

## Reproducing

Compile: `gcc -O3 -fPIC -shared -DSQLITE_VEC_OMIT_FS -I<dir with sqlite3.h and sqlite3ext.h> -I<amalgamation> sqlite-vec.c -o vec0.so`. The benchmark script is not kept; it is a loop that inserts random vectors into `vec0` tables and times `match ... and k = 20` queries.
