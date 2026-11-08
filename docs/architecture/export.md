# Export from the library (workstream 13)

Status: **steps 1 and 2 built** (the decision logic; the job, zip, naming, m3u8). The API, MCP tool, web dialog and retention are not built; see the roadmap, "13. Export from the library".

## ExportPlanner

`App\Domain\Export\ExportPlanner::plan(iterable $tracks, ?string $profile): ExportPlan` takes tracks in export order and returns one `ExportItem` per track plus totals. `decide(File, profile)` is the per-file rule. Nothing is probed, read or written: it uses `files.codec` (falling back to the extension) and `files.bitrate` (kbit/s, or bit/s above 10000, like `Track::bitrateKbps()`). The file is the track's primary present original (`Proxies::original()`).

Profiles and limits are in `config/export.php`. Default profile `mp3-320`.

| Profile | Ceiling | Copied when | Transcoded when |
|---|---|---|---|
| `highest` | none | always | never |
| `mp3-320`, `mp3-256`, `mp3-192`, `mp3-128` | the number | MP3 or AAC at or below the ceiling | lossless (`lossless`), above the ceiling (`above_ceiling`), another lossy codec (`codec_not_accepted`), bitrate unknown (`unknown_bitrate`) |
| `compat` | 320 | MP3 at or below 320, 44.1 kHz, at most 2 channels | everything else, incl. AAC (`codec_not_accepted`), other sample rate (`sample_rate`), more than 2 channels (`channels`); target 44.1 kHz stereo |

Transcode target: MP3 CBR; lossless goes to the ceiling, a lossy file to `min(its own bitrate, ceiling)`, so a file is never upsampled. A track without an own file is `skip` / `no_own_file` (Spotify and DLNA-only tracks).

## ExportPlan

`profile`, `items`, `tracks` (all, skipped included), `copied`, `transcoded`, `skipped`, `estimatedBytes`, `parts`, `exceeds`; `totals()` gives them as an array.

- Estimate: copies count the file size, transcodes `duration × bitrate × 125` bytes (the source size when the duration is unknown). Skipped tracks count nothing.
- `parts`: files are packed in order into zips of at most `EXPORT_PART_GB` (4); a file larger than that gets a part of its own.
- `exceeds`: `max_gb` when the estimate is over `EXPORT_MAX_GB` (8), `dir_max_gb` when over `EXPORT_DIR_MAX_GB` (16). A limit of 0 is off. The caller refuses the export with the estimate.

Environment: `EXPORT_DEFAULT_PROFILE`, `EXPORT_MAX_GB`, `EXPORT_PART_GB`, `EXPORT_KEEP_HOURS` (24, used by the later retention step), `EXPORT_DIR_MAX_GB`.

## The job (step 2)

`App\Jobs\ExportLibrarySelection` (queue `heavy`, `tries` 1, `timeout` `EXPORT_TIMEOUT` 4 h, unique lock per export `public_id`) runs `App\Domain\Export\ExportRunner` on an `exports` row (`App\Models\Export`: selection, profile, options, status queued/running/done/failed/expired, plan totals, `files_done`/`files_total`, `skipped`, `part_files`, `error`, `expires_at`; no per-user fields). The runner resolves the selection to tracks (`ExportSelection`: album by disc/track number, artist by album, playlist order, or a track list), plans it again with `ExportPlanner`, then:

1. Refuses (status `failed`, nothing written) when the plan is over `max_gb`/`dir_max_gb`, nothing can be exported, free space on the disk is below the estimate plus `EXPORT_FREE_MARGIN_MB` (256), or ffmpeg is missing.
2. Per file, in order: copy the original, or transcode with ffmpeg (`libmp3lame` CBR at the plan's bitrate, `-ar`/`-ac 2` when the profile asks, `-map_metadata 0` keeps the tags, `-id3v2_version 3`). ffmpeg runs one file at a time under `nice -n EXPORT_NICE` (10). One file failing is added to `skipped` (`transcode_failed`, `copy_failed`, `file_missing`) and the rest go on; `no_own_file` come from the plan.
3. Writes the result into store-mode zips under `exports/{public_id}/` on the private disk with `ZipWriter` (streamed with `stream_copy_to_stream`; the CRC is read first so no data descriptor is needed; zip64 per entry above 4 GB and for the directory beyond 65535 entries or 4 GB). A new part starts when the next file would take the current one over `EXPORT_PART_GB` (the real size is used, not the estimate). Parts are named `Title.zip` or `Title (part 1 of 3).zip`.
4. Progress: `files_done` of `files_total` on the row after every file.

A run that fails (or whose row is deleted or no longer `running`: that is how a cancel works) removes everything under `exports/{public_id}/`; a killed run (timeout) does so in `failed()`.

**Covers.** A transcode embeds the album cover as a baseline JPEG of at most 500 px (`export.cover_max_px`, 4:2:0): the processed 512 px cover of the artwork pipeline re-encoded by ffmpeg once per album, else the art embedded in the source file, re-encoded the same way. A **copied file is left untouched** (its own tags and cover stay as they are, even if the cover is larger); the only exception is a profile with `id3v23` (`compat`), where a copied MP3 is remuxed with `-c copy -id3v2_version 3`. Transcodes are **not** shared through the proxy cache (the proxy profiles drop tags and the output depends on the cover), so a second export transcodes again.

**Naming** (`ExportNames`). Album and artist exports: `Artist/Album/01 - Title.mp3` (`2-01 - ...` from disc 2). Playlists and track lists: flat, `001 - Artist - Title.mp3` with the position as prefix; option `layout` (`tree`|`flat`) overrides. Forbidden characters `<>:"/\|?*` and control characters become `_`, folder names are cut at 60 characters, file names at 100, no trailing dots or spaces, reserved names (CON, NUL ...) are prefixed, duplicates (compared case-insensitively) get ` (2)`. Playlist exports hold a `playlist.m3u8` (UTF-8, `#EXTINF`, relative paths) in **every** part, listing only that part's files.

Not verified: zip64 above 4 GB (written to the spec, not exercised: tests use small files), transcode speed on a Pi, the extraction on a real head unit. Tests: `tests/Feature/ExportJobTest.php` (real ffmpeg, skipped without `libmp3lame`).

## Not decided here

Option `loudness` (baking ReplayGain into the files), the API, MCP tool, OpenAPI entry, web dialog and retention (deleting expired exports, the `exports/` size cap) are the next steps. A copied MP3 other than in `compat` may still carry ID3v2.4 tags.
