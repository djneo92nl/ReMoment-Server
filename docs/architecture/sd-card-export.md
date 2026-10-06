# SD Card Artwork Export

A zip of artwork laid out exactly as the ESP32 touch client (`ReMomentClient`) reads it from its SD card, so a new card can be filled at once instead of the client downloading hundreds of covers over WiFi. Admin-only (behind the shared admin login), like the other settings actions.

There is one zip per **background size** (`ArtworkBackgrounds::SIZES`): `1024x600` (7" landscape, default), `320x480` (3.5" portrait) and `480x480` (square). The client's card has a single backgrounds folder for its own screen, so each zip holds only that size's backgrounds; the covers are the same in both.

## Zip layout

Extract at the **root** of the SD card:

```
README.txt                         how to extract, when it was built, counts
remoment/covers/{hash}.jpg         byte copy of storage/app/public/artwork/{hash}/320.jpg (320×320)
remoment/backgrounds/{hash}.jpg    byte copy of storage/app/public/artwork/{hash}/bg_{size}.jpg (bg_1024x600.jpg, bg_320x480.jpg or bg_480x480.jpg)
```

`{hash}` is the md5 directory name in `/storage/artwork/{hash}/`, i.e. `md5(original image URL)` — the same hash the client sees in the artwork URLs of the MQTT `/data` payload, `now_playing.artwork` and `GET /api/clients/{api_token}/artwork`. Files are copied, not re-encoded (all ProcessArtwork output is already baseline JPEG), and stored uncompressed in the zip.

## Contents

- The **eight generated source logos** (`SourceLogo::KEYS`), rendered first if missing.
- The covers of the **last N distinct albums played** (`LibraryArtwork::recentCoverUrls()`, N = `config('artwork.recent_albums')`, env `ARTWORK_RECENT_ALBUMS`, default 500), newest first.

A cover whose 320 file or background of the chosen size isn't on disk yet is skipped and counted as `skipped`; `artwork:prerender` (daily) processes the recent albums, so a later build includes them.

## Building

| Trigger | How |
|---|---|
| Admin UI | `/settings/clients` → pick the screen size → **Build zip** (`POST /settings/clients/artwork-export`, body `size=1024x600\|320x480\|480x480`, default `1024x600`, anything else is a validation error) queues `BuildSdCardExport`; that size's row shows "Building…" until it finishes |
| CLI | `php artisan artwork:export-sd [--size=1024x600] [--limit=500]` builds it now and prints the path; `--size=320x480` for the portrait client, `--size=480x480` for the square one; `--queue` queues it instead; an unknown size exits with an error |

`App\Jobs\BuildSdCardExport($limit, $size)` (`ShouldBeUnique` per size, so double clicks queue one build while the two sizes can build side by side) writes `exports/remoment-sd-artwork-{size}.zip` on the private `local` disk (`storage/app/private/`) via a `.tmp` file and rename, so a download never sees a half-written zip, and `exports/remoment-sd-artwork-{size}.json` with `{ built_at, bytes, albums, logos, skipped, limit, size }`. The "pending" flag is a cache key per size (`artwork:sd-export:pending:{size}`, 1 hour TTL) set on dispatch and cleared when the job ends or fails.

## Downloading

`/settings/clients` lists every size with when its zip was last built, its file size and album/logo counts, and a **Download** link (`GET /settings/clients/artwork-export/download?size={size}`, default `1024x600`, served as `remoment-sd-artwork-{size}.zip`; 404 before that size's first build or for an unknown size). A zip from before sizes existed (`remoment-sd-artwork.zip`) is no longer listed; rebuild.

Key files: `app/Domain/Artwork/SdCardExport.php` (paths, layout, meta, per size), `app/Domain/Artwork/ArtworkBackgrounds.php` (sizes), `app/Jobs/BuildSdCardExport.php`, `app/Console/Commands/ExportSdCardArtwork.php`, `app/Http/Controllers/ArtworkExportController.php`, `resources/views/settings/clients.blade.php`.
