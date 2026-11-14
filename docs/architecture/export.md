# Export from the library (workstream 13)

Status: **steps 1 to 5 built, plus AAC, Opus and the loudness option (1.1)** (the decision logic; the job, zip, naming, m3u8; the API, MCP tool and OpenAPI, see [docs/api/export.md](../api/export.md); the web dialog; retention). Only the docs sweep (step 6) and the unverified items remain; see the roadmap, "13. Export from the library".

## Formats (AAC, Opus) and encoders

`config('export.formats')` describes each transcode format: ffmpeg encoders in order of preference, extension, MIME type, container overhead for the estimate (m4a ×1.01, Opus ×1.02) and a compatibility note. `App\Domain\Export\ExportFormats` reads `ffmpeg -encoders` once (cached an hour in the cache store, keyed by the ffmpeg binary; a failed probe is not remembered) and answers `encoder($format)`, `available()`, `availableIds()`, `describe()`:

- **MP3**: `libmp3lame`. **AAC**: ffmpeg's native `aac` encoder; `EXPORT_AAC_ENCODER=libfdk_aac` prefers `libfdk_aac` when ffmpeg was built with it (rare), falling back to `aac`. **Opus**: `libopus`, VBR at the bitrate, always 48 kHz.
- `GET /api/info` `features.export_formats` lists what this server can make; the estimate's `format` says whether the chosen profile's format is available; the dialog disables profiles whose encoder is missing. A profile whose format has no encoder is refused with `422 encoder_unavailable` when files would be transcoded (a plan of copies only still works). An unreadable encoder list is not taken as "none".
- **Compatibility** (the notes shown in the dialog): MP3 plays everywhere; AAC (m4a) plays on phones, Apple devices and most newer players and cars, but some older MP3 players and head units cannot; Opus has the best quality for its size but many cars and MP3 players do not play it. Copies keep their own container: an AAC or MP3 file in an AAC profile is copied as it is, so a mixed folder is normal (as with the MP3 profiles that keep AAC).
- **Tags and cover per container**: MP3 as before (ID3v2.3, attached JPEG). m4a: the source tags become mp4 atoms (`-map_metadata 0`), the cover is an `attached_pic` stream (`covr`), `+faststart`. Opus: Vorbis comments from the source tags; the cover is a `METADATA_BLOCK_PICTURE` comment (base64 FLAC picture block, front cover, JPEG of at most 500 px) written through an ffmetadata file because it is too long for a command line; if the album has no processed cover, the art embedded in the source file is used. ffprobe shows it as an attached image stream.

## Loudness (`options.loudness`: `off` | `album` | `track`)

`ExportLoudness` computes a gain in dB per transcoded file from what the analysis stage stored (`file_analyses`): the track gain is the ReplayGain track tag (`replaygain_track_gain`), else `-18 LUFS - loudness_lufs` (the ReplayGain 2 reference, `export.loudness_reference_lufs`). `album` uses the album tag (`replaygain_album_gain`), else the duration-weighted energy mean of the album's tracks **in this export** (all must be measured), so the balance between an album's tracks stays; a track without an album gain falls back to its track gain, one without any value gets none.

- **Never clips.** A boost is limited to the headroom of the stored true peak minus `export.loudness_margin_db` (0.3) and to `export.loudness_max_gain_db` (12); for `album` the loudest peak of the album's tracks counts. A file without a measured peak is never boosted (cuts always apply). Gains are rounded to 0.1 dB; below that nothing is applied.
- **Only when transcoding.** The gain is `-af volume=XdB` in the same ffmpeg run as the encode. A copied file is **bit-exact** and keeps its level, whatever the option says (the dialog and the estimate say so: `totals.loudness` = `{mode, leveled, unleveled}`, plus the copied count). A transcoded file with no analysis data is left as it is and counted in `unleveled` (analyse the library first).
- A file that got a gain loses its ReplayGain / R128 tags in the output (`replaygain_*`, `r128_*`), because a player would apply the gain a second time; files transcoded without the option keep them.
- Stored in the export's `options` (`loudness` only when `album` or `track`); the estimate and the job use the same planner.

## ExportPlanner

`App\Domain\Export\ExportPlanner::plan(iterable $tracks, ?string $profile): ExportPlan` takes tracks in export order and returns one `ExportItem` per track plus totals. `decide(File, profile)` is the per-file rule. Nothing is probed, read or written: it uses `files.codec` (falling back to the extension) and `files.bitrate` (kbit/s, or bit/s above 10000, like `Track::bitrateKbps()`). The file is the track's primary present original (`Proxies::original()`).

Profiles and limits are in `config/export.php`. Default profile `mp3-320`.

| Profile | Ceiling | Copied when | Transcoded when |
|---|---|---|---|
| `highest` | none | always | never |
| `mp3-320`, `mp3-256`, `mp3-192`, `mp3-128` | the number | MP3 or AAC at or below the ceiling | lossless (`lossless`), above the ceiling (`above_ceiling`), another lossy codec (`codec_not_accepted`), bitrate unknown (`unknown_bitrate`) |
| `compat` | 320 | MP3 at or below 320, 44.1 kHz, at most 2 channels | everything else, incl. AAC (`codec_not_accepted`), other sample rate (`sample_rate`), more than 2 channels (`channels`); target 44.1 kHz stereo |

| `aac-256`, `aac-192`, `aac-128` | the number | AAC or MP3 at or below the ceiling | lossless, above the ceiling, another codec (Vorbis, Opus), unknown bitrate; target AAC in `.m4a` |
| `opus-160`, `opus-128`, `opus-96`, `opus-64` | the number | Opus at or below the ceiling | everything else, including MP3 and AAC (`codec_not_accepted`); target Opus in `.opus` (Ogg) |

Transcode target: the profile's format (`mp3`, `aac`, `opus`; CBR bitrate); lossless goes to the ceiling, a lossy file to `min(its own bitrate, ceiling)`, so a file is never upsampled. A track without an own file is `skip` / `no_own_file` (Spotify and DLNA-only tracks).

## ExportPlan

`profile`, `items`, `tracks` (all, skipped included), `copied`, `transcoded`, `skipped`, `estimatedBytes`, `parts`, `exceeds`, `loudness`, `leveled`, `unleveled`; `totals()` gives them as an array.

- Estimate: copies count the file size, transcodes `duration × bitrate × 125` bytes (the source size when the duration is unknown). Skipped tracks count nothing.
- `parts`: files are packed in order into zips of at most `EXPORT_PART_GB` (4); a file larger than that gets a part of its own.
- `exceeds`: `max_gb` when the estimate is over `EXPORT_MAX_GB` (8), `dir_max_gb` when over `EXPORT_DIR_MAX_GB` (16). A limit of 0 is off. The caller refuses the export with the estimate.

Environment: `EXPORT_DEFAULT_PROFILE`, `EXPORT_MAX_GB`, `EXPORT_PART_GB`, `EXPORT_KEEP_HOURS` (24, used by the later retention step), `EXPORT_DIR_MAX_GB`.

## The job (step 2)

`App\Jobs\ExportLibrarySelection` (queue `heavy`, `tries` 1, `timeout` `EXPORT_TIMEOUT` 4 h, unique lock per export `public_id`) runs `App\Domain\Export\ExportRunner` on an `exports` row (`App\Models\Export`: selection, profile, options, status queued/running/done/failed/expired, plan totals, `files_done`/`files_total`, `skipped`, `part_files`, `error`, `expires_at`; no per-user fields). The runner resolves the selection to tracks (`ExportSelection`: album by disc/track number, artist by album, playlist order, or a track list), plans it again with `ExportPlanner`, then:

1. Refuses (status `failed`, nothing written) when the plan is over `max_gb`/`dir_max_gb`, nothing can be exported, free space on the disk is below the estimate plus `EXPORT_FREE_MARGIN_MB` (256), or ffmpeg is missing.
2. Per file, in order: copy the original, or transcode with ffmpeg (the format's encoder, see Formats, at the plan's bitrate, `-ar`/`-ac 2` when the profile asks, `-map_metadata 0` keeps the tags, the loudness gain when planned). ffmpeg runs one file at a time under `nice -n EXPORT_NICE` (10). One file failing is added to `skipped` (`transcode_failed`, `copy_failed`, `file_missing`) and the rest go on; `no_own_file` come from the plan.
3. Writes the result into store-mode zips under `exports/{public_id}/` on the private disk with `ZipWriter` (streamed with `stream_copy_to_stream`; the CRC is read first so no data descriptor is needed; zip64 per entry above 4 GB and for the directory beyond 65535 entries or 4 GB). A new part starts when the next file would take the current one over `EXPORT_PART_GB` (the real size is used, not the estimate). Parts are named `Title.zip` or `Title (part 1 of 3).zip`.
4. Progress: `files_done` of `files_total` on the row after every file.

A run that fails (or whose row is deleted or no longer `running`: that is how a cancel works) removes everything under `exports/{public_id}/`; a killed run (timeout) does so in `failed()`.

**Covers.** A transcode embeds the album cover as a baseline JPEG of at most 500 px (`export.cover_max_px`, 4:2:0): the processed 512 px cover of the artwork pipeline re-encoded by ffmpeg once per album, else the art embedded in the source file, re-encoded the same way. A **copied file is left untouched** (its own tags and cover stay as they are, even if the cover is larger); the only exception is a profile with `id3v23` (`compat`), where a copied MP3 is remuxed with `-c copy -id3v2_version 3`. Transcodes are **not** shared through the proxy cache (the proxy profiles drop tags and the output depends on the cover), so a second export transcodes again.

**Naming** (`ExportNames`). Album and artist exports: `Artist/Album/01 - Title.mp3` (`2-01 - ...` from disc 2). Playlists and track lists: flat, `001 - Artist - Title.mp3` with the position as prefix; option `layout` (`tree`|`flat`) overrides. Forbidden characters `<>:"/\|?*` and control characters become `_`, folder names are cut at 60 characters, file names at 100, no trailing dots or spaces, reserved names (CON, NUL ...) are prefixed, duplicates (compared case-insensitively) get ` (2)`. Playlist exports hold a `playlist.m3u8` (UTF-8, `#EXTINF`, relative paths) in **every** part, listing only that part's files.

Not verified: zip64 above 4 GB (written to the spec, not exercised: tests use small files), transcode speed on a Pi, the extraction on a real head unit. Tests: `tests/Feature/ExportJobTest.php` (real ffmpeg, skipped without `libmp3lame`) and `tests/Feature/ExportFormatsTest.php` (planner rules for AAC/Opus, loudness gains and the no-clipping limit, real ffmpeg jobs for m4a, Opus and loudness; skipped without the `aac` / `libopus` encoders; the CI runner's apt ffmpeg has both).

## ExportService (shared by API and web)

`App\Domain\Export\ExportService` is the one implementation of `draft()` (selection, alias ids resolved), `estimate()`, `start()` (throws `ExportRefused` with code, message and estimate: over the limits, nothing exportable, ffmpeg missing), `cancel()` and `resource()` (the shape in docs/api/export.md, with fresh signed part URLs). `Api\ExportController` only validates and maps `ExportRefused` to JSON; the Livewire components call the service directly.

## Web (step 4)

Admin login only, gated with the `library` and `file_storage` features (Control: no button; `/settings/storage` redirects to `/not-in-profile`).

- `Livewire\ExportDialog` (`<livewire:export-dialog type="album|artist|playlist" :id="…" />`): an **Export** button on the album, artist and playlist pages, visible to the logged-in admin only. It opens a panel with quality (every profile of `config/export.php`, default `mp3-320`) and folder layout, shows the estimate live (tracks exportable, size, zips, copied, converted, skipped with reasons, a warning over the limits) before anything starts, then **Start export**. It polls every 2 s for progress, shows a download button per signed part (the signed URL of the API, valid 15 minutes, renewed on every render), Cancel while queued or running, Remove when finished. A refusal (`ExportRefused`) is shown in the panel. A `tracks` selection works too (`:track-ids`), no page uses it yet.
- `Livewire\ExportList`: the **Exports** tab of Settings › Files (`/settings/storage?tab=exports`): the last 20 exports with the same status block (`livewire/partials/export-status`), the space used of `EXPORT_DIR_MAX_GB`, polling every 3 s while one runs.

## Retention (step 5)

`php artisan export:prune` (`Domain\Export\ExportRetention`), scheduled hourly, gated with `Profile::allows('file_storage')` (a scheduler test fails for an ungated task):

1. A `done` export past `expires_at` (`EXPORT_KEEP_HOURS`, default 24) loses its files and becomes `expired`; `finished_at` is then the expiry time.
2. `failed` and `expired` rows are forgotten (row and directory deleted) one keep period after they ended.
3. A directory under `exports/` without a row, not modified for an hour, is removed.
4. While `exports/` is over `EXPORT_DIR_MAX_GB`, the oldest `done` exports are expired first. Queued and running exports are never touched.

## Not decided here

Not verified: Opus and m4a playback on real head units and players; the native AAC encoder's quality at 128 kbit/s (libfdk_aac is better but is rarely in ffmpeg builds); the album gain computed from a partial selection (it uses the tracks in the export). A copied MP3 other than in `compat` may still carry ID3v2.4 tags.
