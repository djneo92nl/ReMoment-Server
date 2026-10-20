# Library health

Reports on what needs a look in the own files, built from the audio analysis (`audio-analysis.md`) and the file index (`file-storage.md`). Read only: nothing is changed or deleted from here. Web page `/settings/library-health` (admin login like the other settings pages, linked from Settings → Library Health), counts for clients in `GET /api/library/health` (`docs/api/library.md`).

## Code

`App\Domain\Health\LibraryReports`: one entry per report in `definitions()` (key, title, description), `count($key)`, `rows($key, $limit)` (title, detail, optional album link) and `counts()` (all counts, cached 10 minutes; `forget()` clears it). Each report is a plain query over `files`, `track_files`, `tracks`, `file_analyses` and `metadata`; only present, non-hidden files count. **To add a report** add a definition, a case in `query()` (or its own count and rows), and a test.

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

Findings from the analysis fill in as the nightly `library:analyze` works through the library (`ANALYSIS_BATCH` files a night); a library that was never analysed shows zeros, not errors. `transcodes` and `lossy` are hints, not proof (see `audio-analysis.md`, *Quality*). SQL uses `cast(... as integer)`, so it needs SQLite like the rest of the app.

## Not built yet

Fixing from the page (delete a duplicate, replace a cover, rewrite tags), low-resolution and missing covers (needs the cover size kept per album), inconsistent tags within an album, and AcoustID-based tag suggestions.

## Tests

`tests/Feature/LibraryHealthTest.php` (each report, hidden and missing files, ordering, the page and the API).
