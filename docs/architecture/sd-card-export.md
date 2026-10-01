# SD Card Artwork Export

A zip of artwork laid out exactly as the ESP32 touch client (`ReMomentClient`) reads it from its SD card, so a new card can be filled at once instead of the client downloading hundreds of covers over WiFi. Admin-only (behind the shared admin login), like the other settings actions.

## Zip layout

Extract at the **root** of the SD card:

```
README.txt                         how to extract, when it was built, counts
remoment/covers/{hash}.jpg         byte copy of storage/app/public/artwork/{hash}/320.jpg (320×320)
remoment/backgrounds/{hash}.jpg    byte copy of storage/app/public/artwork/{hash}/bg_1024x600.jpg (1024×600)
```

`{hash}` is the md5 directory name in `/storage/artwork/{hash}/`, i.e. `md5(original image URL)` — the same hash the client sees in the artwork URLs of the MQTT `/data` payload, `now_playing.artwork` and `GET /api/clients/{api_token}/artwork`. Files are copied, not re-encoded (all ProcessArtwork output is already baseline JPEG), and stored uncompressed in the zip.

## Contents

- The **eight generated source logos** (`SourceLogo::KEYS`), rendered first if missing.
- The covers of the **last N distinct albums played** (`LibraryArtwork::recentCoverUrls()`, N = `config('artwork.recent_albums')`, env `ARTWORK_RECENT_ALBUMS`, default 500), newest first.

A cover whose 320 or background file isn't on disk yet is skipped and counted as `skipped`; `artwork:prerender` (daily) processes the recent albums, so a later build includes them.

## Building

| Trigger | How |
|---|---|
| Admin UI | `/settings/clients` → **Build zip** / **Rebuild** (`POST /settings/clients/artwork-export`) queues `BuildSdCardExport`; the card shows "Building…" until it finishes |
| CLI | `php artisan artwork:export-sd [--limit=500]` builds it now and prints the path; `--queue` queues it instead |

`App\Jobs\BuildSdCardExport` (`ShouldBeUnique`, so double clicks queue one build) writes `exports/remoment-sd-artwork.zip` on the private `local` disk (`storage/app/private/`) via a `.tmp` file and rename, so a download never sees a half-written zip, and `exports/remoment-sd-artwork.json` with `{ built_at, bytes, albums, logos, skipped, limit }`. The "pending" flag is a cache key (`artwork:sd-export:pending`, 1 hour TTL) set on dispatch and cleared when the job ends or fails.

## Downloading

`/settings/clients` shows when the zip was last built, its size and album/logo counts, and a **Download** link (`GET /settings/clients/artwork-export/download`, served as `remoment-sd-artwork.zip`; 404 before the first build).

Key files: `app/Domain/Artwork/SdCardExport.php` (paths, layout, meta), `app/Jobs/BuildSdCardExport.php`, `app/Console/Commands/ExportSdCardArtwork.php`, `app/Http/Controllers/ArtworkExportController.php`, `resources/views/settings/clients.blade.php`.
