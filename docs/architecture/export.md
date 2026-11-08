# Export from the library (workstream 13)

Status: **step 1 built** (the decision logic). The job, zip, API and web dialog are not built; see the roadmap, "13. Export from the library".

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

## Not decided here

ID3v2.3 tag writing (`compat`; the planner cannot see a file's tag version, so a copied MP3 may still carry ID3v2.4 until the job step rewrites tags), cover handling, naming and the proxy profiles for the ffmpeg run are for step 2. Tests: `tests/Feature/ExportPlannerTest.php`.
