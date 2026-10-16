# File storage

ReMoment can serve its own music files instead of relying on a DLNA server. Files are described in their own tables, separate from tracks; Flysystem (Laravel disks) only moves the bytes.

## Tables

- `storage_locations` — `name`, `driver` (`local` | `s3`), encrypted JSON `config` (local: `root`; s3: `bucket`, `region`, `key`, `secret`, `endpoint`, `use_path_style_endpoint`, `prefix`), `role` (`library` = the organized home of files, `inbox` = where imports arrive), `last_scanned_at`. `StorageLocation::disk()` builds the Flysystem disk (`Storage::build`). Several locations can exist at once; all feed one library. Secrets never leave the server (the API shows `config` without `key`/`secret`).
- `files` — one row per stored file: `storage_location_id`, `path` (relative to the location root), `hash` (sha256 of the content), `size`, `mtime`, `mime_type`, `extension`, audio properties, `status` (`present` | `missing`). A file knows nothing about tracks and can exist without one.
- `track_files` — links a track to its files: `role` (`original` | `proxy`), `profile` (e.g. `mp3-320`), `derived_from_file_id`, `is_primary`. A track can have several files; proxy *generation* (transcoding) is not built yet, the schema and stream selection are.

## Code (`app/Domain/Storage/`)

- `FileStore` — bytes of one location: put/read/delete/move, streaming sha256, listing, `withLocalPath()` (real path locally, temporary copy for remote disks).
- `AudioTags` — tags and audio properties of a local file (getID3).
- `FileIndexer` — `index($location, $path)`: hash + tags → `files` row → track through `LibraryIdentity` (track external id `local:file:{file id}`) → `track_files`. A file whose hash exists at another path whose old file is gone is a **move**: the row, and so the track, is kept. Unchanged files (size + mtime) are skipped.
- `LibraryOrganizer` — layout `{album artist}/{album} ({year})/{disc}-{track} {title}.{ext}` (illegal characters cleaned, `(2)` on collisions). Moves within a location use the disk's move; across locations or drivers the file is copied, the copy's hash verified, then the source removed.
- `LibraryFiles` — management: `upload` (inbox → index → library layout), `importInbox`, `reorganize`, `deleteFile`, `deleteTrack`, `move` (rename), `retag` (writeTags + queue enrichment), `rematch` (re-read the tags and tie the file to its track again; the one place to change how songs are matched is `FileIndexer::attach`), `setHidden` / `setFolderHidden`, `enrich`, `writeTags` (getID3, mp3 pure PHP; flac needs `metaflac`, ogg `vorbiscomment`; re-reads the file and re-files the track), `verify` (re-hash, flag missing), `duplicates` (same hash).
- `App\Services\Local\LocalLibraryScanner` — scans a location through the indexer and marks files that disappeared `missing`.

A track made from a file gets source `dlna` and a `dlna_url` (metadata source `dlna:local`) of `{APP_URL}/stream/{track}`, so playback, library scopes and `library:merge-duplicates` work unchanged; a proper `local` library entry in `LibrarySources` is still to do. `APP_URL` must be an address the speakers can reach.

A track whose last file is deleted is removed, unless another source (a real DLNA server, Spotify) still plays it; then only the local stream is unlinked.

## Embedded covers

`FileIndexer::attach` gives an album **without an image** the picture embedded in its file (`AudioTags::read()['picture']`: the front cover when the file says which, else the first). `App\Domain\Storage\CoverExtractor` keeps it once, by content, on the private `local` disk (`storage/app/private/covers/{sha1}`, `App\Domain\Artwork\LocalCover`) and sets `albums.images` to the pseudo URL `remoment:cover/{sha1}`; `ProcessArtwork` reads that from disk (no download), so it gets the same proxies, backgrounds and colours as any cover. An album that already has an image (Spotify, a DLNA server, the Cover Art Archive) keeps it. Pictures under 100 px, over 15 MB or not JPEG/PNG/GIF/WebP are ignored.

Albums indexed before this existed, or retagged since, are filled by `php artisan library:extract-covers [--limit=50]`, which every file scan also runs. An album whose files carry no usable picture is marked (`cover_checked_at`, source `local`) and not read again; the Cover Art Archive lookup of the metadata enrichment can still fill it.

## Streaming

`GET /stream/{track}` (unauthenticated, like the REST API) sends the track's primary original, or with `?profile=low` the proxy of that profile, falling back to the original. Local files get range support (seek). Remote files are redirected to a 30-minute temporary URL when the disk can sign one (S3), else streamed through the server. Missing files give `404`. Not tested yet on real speakers: whether Sonos/ASE follow the redirect.

## Queued tasks and the settings page

Scanning, importing the inbox and reorganizing can take long, so the web UI and `?queue=1` run them on the queue (`App\Jobs\RunStorageTask`, one per kind, per location for scans; `ShouldBeUnique`). `App\Domain\Storage\StorageTasks` queues them and keeps their state (`queued` | `running` | `done` | `failed`, a summary message, progress while scanning) in the cache for a day; a task queued or running for over two hours counts as lost (no worker) and can be queued again. The queue worker must be running (`composer dev`, supervisor). An import (like a scan) ends by queueing enrichment and audio analysis for a bounded batch of the new tracks (`Enrichment::SCAN_BATCH`, `analysis.scan_batch`); the daily `library:enrich` / `library:analyze` work off the rest.

`/settings/storage` (admin login; three Alpine tabs, `?tab=files|upload|locations`: Livewire `StorageFiles`, `LibraryUploader`, `StorageManager`):
- add/edit/remove locations (local folder must exist on the server; S3 key and secret are never sent back to the browser: leave blank to keep), test reachability;
- Scan per location, Import inbox, Reorganize library — each queued, with live status (the page polls every 2 s while something is active);
- file browser (`StorageFiles`): folders of one location with breadcrumbs (the folder names are cut out of `files.path` in SQL, folders first, 50 rows per page); a search or a state filter (missing, hidden, not parsed, not enriched) lists matching files flat. Per file the state chips: **parsed** (tags read and matched to a track), **enriched** (or "n to fetch": `Enrichment::pendingByTrack()`, one query per page) and **analysed**. The row menu: edit tags (writes them into the file, matches it again, moves it to its new path and queues enrichment; the editor also has a "What could this be?" panel: a title/artist hint, filled from the tags or file name, searches the library at once and MusicBrainz on request (`TrackSuggestions`), and "Use" on a candidate fills the tag form, nothing is written until Write tags), hide from / show in the library, match again, fetch metadata again, verify, delete. Folders can be hidden or shown as a whole.
- **Hide from library**: `files.hidden_at`. `LibrarySources::tracks()` (and so albums, artists, playlists, the REST API, MCP, smart playlists and playback) leaves out a track whose own files are all hidden, unless another source (a DLNA server, Spotify) also plays it. The file stays on disk, in `/stream` and in the browser, dimmed, so it can be shown again.
- **Add music** (`LibraryUploader`): a drop zone for audio files, with a table of each file's step.

### Browser upload

Files dropped on `/settings/storage` go through `App\Http\Controllers\LibraryUploadController` (session login, CSRF) and `App\Jobs\ProcessLibraryUpload`. The page sends two requests per file, two files at a time:

1. `POST /settings/storage/uploads` `{name, size}` registers a row (`LibraryUploads`, cache, newest first, 100 kept) and refuses non-audio names at once.
2. `POST /settings/storage/uploads/{id}` sends the bytes (XHR, so the page shows the percentage). `LibraryFiles::stageUpload()` stores them in the library location under `_uploads/{ulid}/` (never the inbox: that is often the existing music, mounted read-only) and queues `ProcessLibraryUpload`.

The job runs the steps of `LibraryFiles::upload()` one by one and records each: **parsing** (`indexStaged`: tags, track, cover; an unreadable file is removed again), **copying** (`placeInLibrary`: organized layout, copy, hash check, source removed), **enriching** (`Enrichment::queue()` for the track, plus audio analysis for the file when enabled). The table calls a file *done* when every source the job queued is fetched (`Enrichment::pendingSources()`), or after 15 minutes, naming the sources that did not answer (rate limited or nothing found). A failure keeps the step it failed in; a file that failed after parsing stays in `_uploads/` in the library (a scan indexes it from there). Rows are progress, not data: they expire after a day and a row without progress for two hours shows as failed.

The size limit is PHP's `upload_max_filesize` / `post_max_size` (100M in `docker/*/php.ini`) and any proxy in front; a larger file fails at the upload step.

All locations are also scanned nightly at 03:30 (`library:scan-files --queue`).

## Playing on a speaker

- **Address:** the speaker must be able to reach the stream URL. It is built when a track is played (`StreamUrl::for()`) from `STREAM_BASE_URL` (config `app.stream_url`, e.g. `http://192.168.1.48:8080`), falling back to `APP_URL`; never stored, so changing it needs no rescan. On a dev machine `APP_URL` is often a name only that machine resolves (`remomentserver.test`), which a speaker cannot; with Sail use the published port.
- **No session:** `/stream/{track}` skips the cookie/session/CSRF middleware, so a speaker's requests write nothing.
- **Queue:** playing library content on a B&O ASE speaker first empties the speaker's own play queue (item by item through the ids it lists, best effort), so earlier Tidal or album items do not play on after it. Sonos already replaces its queue.
- **Track details:** for a track of our own files the ASE queue item also carries name, track number and cover (`ContentControls::ownTrackDetails`); no `duration`, which the speaker keeps but the B&O app then shows as seconds (-3166:38 for a 190 s track). Probed on a BeoSound Essence MK2: the track keeps `name`, `duration`, `trackNumber`, `genre`, `id` and `image`; fields inside `dlna` are accepted but dropped; `artist` and `parentAlbum` are answered with `400` (they need provider ids), so artist and album come from the file's tags when it plays. If the speaker rejects the item the plain one is sent (logged as a warning). DLNA servers' tracks always use the plain item. The queue-item fields and the per-item `DELETE BeoZone/Zone/PlayQueue/{id}` follow what the speaker itself lists; not yet verified on every model.
- `DlnaTrackLookup::byUrl()` recognizes our stream URLs by `/stream/{id}`, whatever host they were built with, so the speaker's queue ("up next") resolves them.

## Commands

```bash
php artisan library:scan-files [location] [--queue]   # name or id; default all; --queue runs it on the queue
php artisan library:import [--keep]        # inbox → library, organized; --keep copies and verifies but leaves the originals in the inbox
php artisan library:reorganize [--dry-run]  # re-apply the layout
php artisan library:verify-files            # re-hash, flag missing, report duplicates
```

## Admin API (`admin.token`)

```
GET|POST        /api/admin/storage/locations              list / create {name, driver, role, config}
PUT|DELETE      /api/admin/storage/locations/{id}         update / delete (422 while it has files)
POST            /api/admin/storage/locations/{id}/test    can it be listed (502 with the reason if not)
POST            /api/admin/storage/locations/{id}/scan    → { changed, counts }; with ?queue=1 → 202 { status: queued } (409 while one is queued or running)
POST            /api/admin/storage/import                 inbox → library (keep=true: copy and verify, originals stay in the inbox)
POST            /api/admin/storage/reorganize             { dry_run? } → { moves: [{file_id, from, to}] }
POST            /api/admin/files                          multipart `file` → { file, track_id }
GET|DELETE      /api/admin/tracks/{id}/files              files of a track / delete the track and its files
DELETE          /api/admin/files/{id}                     delete a file (and its track if it was the last)
POST            /api/admin/files/{id}/move                { path, storage_location_id? }
PUT             /api/admin/files/{id}/tags                { title, artist, album, album_artist, year, track_number, disc_number, genre }
POST            /api/admin/files/{id}/verify              → { result: ok | missing | changed }
```

`422` invalid request (e.g. no library location yet), `502` the disk or tag tool failed. Scans without `?queue=1` run inside the request.

## Not built yet

ffmpeg proxies, `X-Accel-Redirect`/sendfile handoff, S3 against a real bucket, a `local` entry in `LibrarySources`.
