# Similar tracks (built)

"Tracks like this one", a radio from one track, and the hook for the Discover hub, from the Discogs-EffNet embeddings the Essentia sidecar computes for each analysed own file (`docker/analysis/app.py` runs `discogs-effnet-bs64-1` for the mood, danceability, voice and genre heads and now also returns its mean embedding). API: [../api/similar-tracks.md](../api/similar-tracks.md), web piece: [../frontend/similar-tracks.md](../frontend/similar-tracks.md). Full profile only (`analysis` gate); needs the ML sidecar (`ANALYSIS_ESSENTIA_ML`).

## What is built

- **The sidecar returns the embedding.** `POST /analyze` answers `embedding` (1280 numbers, the mean over the track's patches, rounded to 6 decimals, about 15 kB of JSON) and `embedding_model` next to the existing fields, whenever the effnet model is loaded. Without ML nothing changes.
- **Storage: the table is the truth.** `file_embeddings` (one row per file, primary key `file_id`, cascade on delete): `model`, `dimensions`, `vector` (a BLOB of 1280 little-endian float32, **L2-normalised by the server**, so cosine similarity is the dot product), `content_hash` (the `files.hash` it was made from). `App\Domain\Similar\Embeddings` packs and validates (wrong length, non-numbers, NaN, zero vector: refused, nothing stored) and keeps a generation counter that result caches include. A vector whose `content_hash` no longer matches the file's analysis, or whose `model` is not the current one, is ignored until the analysis makes a new one, so a changed file never matches with stale sound and a later model never meets vectors of this one. About 5 kB per track (50k tracks: 256 MB).
- **Stage version.** The Essentia stage version is `2+ml` with ML (it was `1+ml`; `1` without ML is unchanged). **Existing analysed files are therefore picked up by the nightly `library:analyze` backlog and analysed again for this stage only** (it works through them at `ANALYSIS_NIGHT_HOURS` per night; nothing was run when this was built). **Rebuild and restart the sidecar image before the app gets the new version:** against an old image the re-run gets no `embedding` in the answer and records the stage as done without one, and those files stay without until the stage version changes again (a one-line bump of `EssentiaStage::version()` re-runs them). `features.similar_tracks` in `GET /api/info` turns true as soon as one file has an embedding.
- **The query** (`App\Domain\Library\SimilarTracks`): the seed is the track's primary file's embedding; candidates are the tracks of the scope (`LibrarySources::tracks`, client-hidden libraries, `device_id` through `LibraryPlayback::playableTracks`) that have a current embedding, minus the track itself, the same AcoustID recording and a same-artist same-title track (as `MixesWith`). The nearest `similar.max_results` (200) are kept and cached for `similar.cache_seconds` (5 minutes) per track, scope and device, dropped as soon as any embedding is written. Ranking: similarity descending, then track id.
- **Two engines behind one answer.**
  - **`php` (default):** `App\Domain\Similar\VectorScan`, an exact scan: rows stream in chunks of 500 from the database and each is a dot product against the seed in PHP, keeping the best k in a heap. Needs nothing and works on SQLite and MariaDB.
  - **`sqlite-vec` (`SIMILAR_ENGINE=sqlite-vec`, optional):** `App\Domain\Similar\SqliteVecIndex` follows the spike's decisions below: its own `Pdo\Sqlite` connection with the library loaded by absolute path (`SIMILAR_VEC_EXTENSION`), vectors bound as BLOBs, a `vec0` table (float32, cosine, rowid = file id) in a separate file (`SIMILAR_VEC_PATH`, default `storage/app/vectors.sqlite`), built from the table by `php artisan similar:index` (scheduled daily 04:40 only while it is the chosen engine, Full profile; `--status` shows rows against the table). A query asks the index for `max_results x similar.vec_overfetch` (4) nearest rows of the whole library, narrows them with the scope query, and **falls back to the scan** whenever the library cannot be loaded, the file is missing, its row count differs from `file_embeddings` (out of date), or the scope cut the list below `max_results` while the index's list was full. The extension is never needed by tests or by a normal install.
- **Radio:** `SimilarTracks::radio()` is the seed followed by the nearest `similar.radio_length - 1` (49) tracks the device can play, in similarity order, played once through `LibraryPlayback::playTracks` (no refill). REST `POST /api/devices/{id}/library/play-similar`, MCP `play_from_library` type `similar`, scene operation `playSimilarTracks`, and the web piece's "Play similar" button all use it.
- **Web and hub:** Livewire `TrackSimilar` (album page's Track details; reusable) and `SimilarTracks::items($limit)`, the rows of a Discover `track` section, so the hub's `SLOT similar_tracks` is one more `$sections[] = ...` ([../frontend/discover.md](../frontend/discover.md)).

## Measured: the PHP scan on this build

`SimilarTracks::matches()` itself (cold: no cache; chosen scope; `max_results` 200), 1280-number random unit vectors in a throwaway SQLite file with the real tables, PHP 8.4.4 CLI without JIT on an Apple-Silicon Mac, three seeds per size:

| Tracks with an embedding | Query time | Peak memory |
|-----:|-----:|-----:|
| 2,000 | about 0.09 s | 49 MB (flat) |
| 10,000 | about 0.55 s | 49 MB |
| 20,000 | about 1.3 s | 49 MB |
| 50,000 | about 5.0 s | 49 MB |

Of the roughly 55 µs per row, the dot product in PHP is about 28 µs (16 µs of that is only unpacking the 1280 floats) and the rest is reading and hydrating the row; memory does not grow because rows stream. Results are cached, so this is paid once per seed, scope and device per five minutes, and again after any new embedding. **The development database has no embeddings** (the sidecar had not returned them and no file was re-run), so the numbers are synthetic; real vectors cost the same per row.

**Pi budget (an estimate, not measured).** Full needs a 64-bit board with 4 GB (a Pi 4 or 5 class machine); by the usual PHP ratio that is several times slower than this Mac, so assume 4 to 8 times the table: a library of 5,000 tracks answers in roughly 1 to 2 s the first time, 20,000 tracks in 5 to 10 s. The recommendation: **the scan is fine up to about 10,000 tracks on a Pi, and above roughly 20,000 tracks (or any time the first query must feel instant) turn on `sqlite-vec`**, which the spike measured at 33 ms for 20,000 and 89 ms for 50,000 on the same class of Mac (about 27 times faster than the scan). The PHP side never loads a vector it does not need: the scope narrows the rows in SQL first. The benchmark script is not kept; it seeds the tables with random vectors and times `matches()` three times per size.

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

## Not verified (spike)

- **x86_64 and 32-bit ARM.** Only aarch64 was built and run. A prebuilt x86_64 library exists; there is no Linux armv7 build in the release (only Android), so a Pi 2/3 on a 32-bit OS would compile it from source: expected to work as plain C, not tried.
- **Real embeddings.** The test used random vectors, which have no structure and tiny gaps between neighbours, so int8 ranked differently from float at 2,000 rows (the same top hit at 20k and 50k). Whether int8 keeps the order for real Discogs-EffNet vectors needs a check on real analysed tracks; float32 is the safe default.
- **Raspberry Pi speed.** All timings are from an Apple Silicon machine through Docker. A Pi will be several times slower; not measured.
- **The sidecar side.** Returning the mean embedding from `/analyze`, the backlog cost of re-analysing existing files, and whether `discogs_multi_embeddings` (contrastive, a separate model) ranks better than the genre-trained embedding the sidecar runs today.
- sqlite-vec is pre-v1 ("expect breaking changes"); pin the version.

## Reproducing

Compile: `gcc -O3 -fPIC -shared -DSQLITE_VEC_OMIT_FS -I<dir with sqlite3.h and sqlite3ext.h> -I<amalgamation> sqlite-vec.c -o vec0.so`. The benchmark script is not kept; it is a loop that inserts random vectors into `vec0` tables and times `match ... and k = 20` queries.

## Still unverified

- **The whole chain on real audio.** Nothing here ran against a real ML sidecar: the sidecar change (`embeddings.mean(axis=0)` of `TensorflowPredictEffnetDiscogs`'s output) is written from Essentia's model page like the rest of `app.py`, and the tests fake its answer. Check `curl .../analyze` on one file returns `embedding` with 1280 numbers, then look at the neighbours of a few known tracks before relying on the order.
- **The sqlite-vec path in this repository.** Built from the spike's notes; only its decision logic (current, stale, missing, cut short) is tested, through a fake `VectorIndex`. `similar:index` and the `vec0` queries have not run against the real library here.
- **Ranking quality.** Cosine on the genre-trained Discogs-EffNet embedding is what the plan chose; whether `discogs_multi_embeddings` (contrastive) ranks better, and whether int8 keeps the order for real vectors, is open. float32 is stored, so a change of model means a new stage version and a `model` value, nothing to migrate.
- **Pi timing** (see above) and the 32-bit Pi, which cannot run the ML sidecar at all.
