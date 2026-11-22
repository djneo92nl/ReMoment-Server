# Tag editor for own files

Workstream 8.4. `App\Domain\Storage\TagEditor` is the **one place that writes tags, a cover or a new name into a file of the library**. The admin API ([../api/tag-editor.md](../api/tag-editor.md)), the web pages ([../frontend/tag-editor.md](../frontend/tag-editor.md)), MCP `edit_tags`, `LibraryFiles::retag` / `writeTags` and everything that accepts a suggestion (12.4, [../api/suggestions.md](../api/suggestions.md)) go through it; nothing else opens a file to write tags.

## What an edit does

1. **Guard.** Files in an `inbox` location are refused (the inbox is often the user's own music, mounted read-only, and the import copies out of it: `importInbox`, `--keep`); so are formats that cannot be written, missing files, and a server without ffmpeg. Nothing is changed on a refusal.
2. **Snapshot** of what the file holds now: the editable tags, whether it has an embedded cover (its bytes go to `storage/app/private/tag-snapshots/{file id}`), its path. One per file (`file_tag_snapshots`): only the last edit can be undone.
3. **Safe write** (`FileStore::rewrite`, `TagWriter`): ffmpeg makes a copy with `-c copy` (the audio is not re-encoded), `-map_metadata 0` and the new `-metadata` values, so **every other tag and the cover are carried over**; `-metadata title=` (an empty value) removes a field. The copy is made next to the original as a hidden `.name.edit-xxxx.ext` (scans skip dotfiles) and **read back with getID3**, the library the indexer reads with: if it does not read as audio, or does not hold the tags or cover that were asked for, it is thrown away and the original stays. Only then is it renamed over the original (atomic on a local disk, one put on S3), with the original's permissions. A failure at any step leaves the file exactly as it was.
4. **Index again** (`FileIndexer::index`): new hash, size and mtime in `files`, the tags re-read, the track found or made again through `LibraryIdentity` (`local:file:{id}`), so the track keeps its identity while its artist, album and name follow the tags. Tracks left without a file are released like on delete; **an album or artist the change left without tracks is removed** (a renamed album would otherwise stay behind empty).
5. **The audio did not change**, so what was derived from it follows the file to its new hash instead of being computed again: `file_analyses`, `file_embeddings` and `section_embeddings` rows get the new `content_hash`.
6. **Organize** (default on, `organize=false` skips it): the file moves to the library layout for its new tags (`{album artist}/{album} ({year})/{disc}-{track} {title}.{ext}`, `LibraryOrganizer`), which is the "name" edit.
7. The caller queues enrichment for the track it now matches (`LibraryFiles::enrich`), like after any retag.

**Undo** (`TagEditor::undo`) writes the snapshot back through the same safe write (cleared fields are cleared again, the previous cover returns, or the added cover is removed), moves the file back to the path it had, and deletes the snapshot. It is refused when the file's hash no longer matches what the edit produced (it changed again), or the stored cover is gone.

## Album batch

`editAlbum($album, $tags, $perFile, $cover)`: `$tags` go to every own file (album, album artist, artist, year, genre, disc number), `$perFile[file id]` to single files (title, track number, artist, disc number), a cover is embedded in each file **and becomes the album's picture** (`albums.images`, the artwork pipeline processes it from the private disk). Files that cannot be edited (inbox, unsupported format) are skipped and named; one that fails is named and the others still go ahead, each with its own snapshot. `undoAlbum` undoes the files of the album that have a snapshot. The result names the album id afterwards (a new album name is another record).

## Formats

| | tags | cover |
|---|---|---|
| mp3 | yes (ID3v2.3, no ID3v1) | yes |
| flac | yes | yes |
| m4a | yes, not exercised by tests | yes, not exercised by tests |
| ogg / oga / opus | yes, not exercised by tests (ffmpeg here has no Vorbis encoder to make a fixture) | no (a base64 comment; refused with a clear message) |
| wav, aiff, wma, aac | not editable | – |

## Changed behaviour (2026-11-23)

The earlier writer (getID3 `getid3_writetags`, metaflac, vorbiscomment) **replaced every tag frame it was not given**: a retag dropped the embedded cover, the MusicBrainz ids and ReplayGain. The editor replaces it. `AudioTags::read` also read a single ID3 `TXXX` value (MusicBrainz release id, barcode) as a list and took its first character; fixed, so an MP3 rip's release id links to the physical collection again.

## Not done

- Per-file rename of the file name alone (the layout name follows the tags; the free-form rename stays `POST /api/admin/files/{id}/move`).
- Writing wav, aiff, wma and aac tags; Ogg/Opus covers.
- More than one level of undo.
- Real-hardware and real-collection checks: tested with ffmpeg-made mp3 and flac only; large FLAC on a Pi (a temp copy of the file is written next to it, so free space of one file is needed) is not measured.
