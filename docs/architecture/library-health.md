# Library health

Reports on what needs a look in the own files, built from the audio analysis (`audio-analysis.md`) and the file index (`file-storage.md`). Read only: nothing is changed or deleted from here. Web page `/settings/library-health` (admin login like the other settings pages, linked from Settings → Library Health), counts for clients in `GET /api/library/health` (`docs/api/library.md`).

## Code

`App\Domain\Health\LibraryReports` (`suggestions` delegates to `App\Domain\Health\Suggestions`): one entry per report in `definitions()` (key, title, description), `count($key)`, `rows($key, $limit)` (title, detail, optional album link) and `counts()` (all counts, cached 10 minutes; `forget()` clears it). Each report is a plain query over `files`, `track_files`, `tracks`, `file_analyses` and `metadata`; only present, non-hidden files count. **To add a report** add a definition, a case in `query()` (or its own count and rows), and a test.

`App\Http\Controllers\LibraryHealthController` (`index`, `show`), views `resources/views/settings/library-health*.blade.php`.

## Reports

| Key | Finds | From |
|-----|-------|------|
| `transcodes` | lossless files whose spectrum ends where a lossy encoder cuts it (`lossless_suspect` at least 0.5), most suspect first | quality stage |
| `damaged` | the decoder complained, or the file decodes more than 2 s shorter than its tags say | quality stage (`decode_warnings`) |
| `clipping` | flat, repeated peaks, most peaks first | quality stage |
| `duplicates` | one recording (AcoustID id) in more than one present file, the best file first (lossless, then bit depth, bitrate, cutoff), all paths listed | AcoustID stage |
| `lossy` | tracks whose primary file is lossy, worst spectrum first, files not yet analysed last: candidates to replace with a lossless copy | quality stage, file properties |
| `incomplete` | albums with own files and fewer tracks than the MusicBrainz release lists (`track_count`), the biggest gap first; a different edition (deluxe, regional) shows up here too | MusicBrainz album metadata |
| `unlinked` | present files no track plays | file index |
| `missing` | files indexed earlier and no longer on disk | file index |
| `covers` | albums with own files and no cover, a tiny cover (shorter side under `artwork.min_cover_px`, 500), a non-square cover (sides differ by more than `artwork.square_tolerance`, 2 %), or an embedded cover whose hash differs from the cover in use; one row per album, every reason listed, worst first. **No analysis needed**: works in Standard and Full (the `cover_health` profile feature covers its findings URLs; the counts and the overview page stay Full) | album metadata `cover_size`, `cover_sha1` (written by `ProcessArtwork` through `App\Domain\Artwork\CoverFacts`), `embedded_cover_sha1` (written by `FileIndexer::attach`) |
| `suggestions` | tag and cover suggestions: files whose title, artist or missing album year disagree with the MusicBrainz recording the fingerprint found, albums with a poor cover that have a MusicBrainz release, inconsistent track numbers within an album. Accept (writes the tag through the tag editor, or fetches the Cover Art Archive front image) and dismiss are admin routes; see [suggestions.md](../api/suggestions.md). Full | AcoustID stage, track metadata `mb_title`/`mb_artist`/`mb_year`, cover facts |

Findings from the analysis fill in as the nightly `library:analyze` works through the library (`ANALYSIS_BATCH` files a night); a library that was never analysed shows zeros, not errors. `transcodes` and `lossy` are hints, not proof (see `audio-analysis.md`, *Quality*). SQL uses `cast(... as integer)`, so it needs SQLite like the rest of the app.

## Not built yet

Fixing from the page for the other reports (delete a duplicate, rewrite tags by hand). The `suggestions` report is the one with actions: accept replaces a cover from the Cover Art Archive (the replace action of roadmap 12.5, per suggestion, never in bulk) or writes a tag (12.4, [suggestions.md](../api/suggestions.md)); a suggested track number is not built.

## Cover facts

The `covers` report is a PHP-side classification of one query (albums with a present, non-hidden own file, plus three metadata values), because the size is stored as `WxH` text. `cover_size` and `cover_sha1` describe the **original** image (not the 512 px proxy) and are written when `ProcessArtwork` processes a cover, for every album that uses that URL; a cover processed before this existed (the job returns early for a complete cache entry) is measured by `php artisan artwork:measure-covers [--limit=200]` (daily, library profile; an embedded cover is read from disk, any other downloaded). An album whose size is unknown is never reported as tiny or crooked, only as having no cover. The embedded hash is the sha1 of the picture bytes of the last file the indexer read for the album; "differs" needs both hashes, so a remote cover that was never measured is not compared.

## Tests

`tests/Feature/Library/TagSuggestionsTest.php` (suggestions, accept/dismiss, cover job, MCP, page), `tests/Feature/LibraryHealthTest.php` (each report, hidden and missing files, ordering, the page and the API; the cover findings, the threshold from config, Standard access, the measure command).
