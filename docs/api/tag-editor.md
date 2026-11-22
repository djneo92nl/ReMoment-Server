# Tag editor (admin)

Workstream 8.4, design in [../architecture/tag-editor.md](../architecture/tag-editor.md). Edit the tags, cover and names of the server's **own files** (storage locations with role `library`); one file, or a whole album in one call. All routes need the admin token (`Authorization: Bearer rmt_…`, see [admin-auth.md](admin-auth.md)) and exist in the Standard and Full profiles (the `file_storage` gate).

Fields: `title`, `artist`, `album`, `album_artist`, `year` (four digits, or a date), `track_number`, `disc_number` (whole numbers; 0 removes), `genre`. A **missing** field (or `null`) is left alone; to remove a field list it in `clear` (an empty string cannot be sent: the API turns it into `null`).

An edit rewrites the file as a safe copy (ffmpeg, no re-encode, every other tag and the cover kept) and swaps it in; the file is indexed again, matched to its track, laid out in the library structure unless `organize=false`, and enrichment is queued. A change of album or artist name moves the track to that album/artist; one left empty is removed. Files in an **inbox** location are never edited (`422`). Errors: `422 invalid` (inbox file, unsupported format, nothing to change, bad field, bad cover, undo not possible), `502 storage_error` (the disk or ffmpeg failed; the file is as it was).

```
GET    /api/admin/files/{id}/tags            the tags the file holds now
PUT    /api/admin/files/{id}/tags            { title?, artist?, album?, album_artist?, year?, track_number?, disc_number?, genre?, clear?: [field], organize?: bool }
POST   /api/admin/files/{id}/tags/undo       put back the last edit (tags, cover, path)
POST   /api/admin/files/{id}/cover           multipart `image`, or JSON `image_base64`: a JPEG or PNG, at least 100 px, at most 10 MB
DELETE /api/admin/files/{id}/cover           take the embedded cover out
GET    /api/admin/albums/{id}/tags           every own file of the album with its tags
PUT    /api/admin/albums/{id}/tags           { tags?: {…}, files?: { "<file id>": { title?, track_number?, artist?, disc_number? } }, clear?: [field], organize?: bool }
POST   /api/admin/albums/{id}/tags/undo      undo the last edit of every file of the album that has one
POST   /api/admin/albums/{id}/cover          multipart `image` or `image_base64`: embedded in every file and used as the album picture
```

A file answers (the previous `PUT /admin/files/{id}/tags` answer plus the state):

```json
{ "id": 91, "storage_location_id": 1, "path": "Band/LP (1999)/02 Song.mp3", "hash": "…", "size": 4012331, "mime_type": "audio/mpeg",
  "codec": "mp3", "bitrate": 320000, "sample_rate": 44100, "bit_depth": null, "duration": 241, "status": "present", "track_id": 812,
  "tags": { "title": "Song", "artist": "Band", "album": "LP", "album_artist": "", "year": "1999", "track_number": "2", "disc_number": "", "genre": "Rock" },
  "has_cover": true, "can_undo": true, "editable": true, "cover_editable": true }
```

`tags` is read from the file, every value a string ('' when the file has none). `editable` is false for inbox files, unsupported formats or a server without ffmpeg; `cover_editable` is false for Ogg/Opus; `can_undo` is true while the last edit can be undone (the file has not changed since).

`GET /albums/{id}/tags` answers `{ album_id, name, files: [{ file_id, path, track_id, tags, has_cover, can_undo, editable, cover_editable }], can_undo, editable }`. The batch calls (`PUT`, `POST …/undo`, `POST …/cover`) answer:

```json
{ "album_id": 77, "edited": [91, 92, 93],
  "skipped": [{ "file_id": 94, "reason": "inbox" }],
  "failed": [{ "file_id": 95, "error": "The new version of the file does not hold the title that was asked for; the original was kept." }] }
```

(`undo` answers `undone` instead of `edited`, without `skipped`.) `album_id` is the album the files are in now: a new album name is another record, so follow it. A failing file does not stop the others; each has its own undo.

**Unverified:** only mp3 and flac are exercised by tests; m4a, ogg and opus are written the same way but not tried on real files.

## MCP

`edit_tags` (admin): `show`, `album`, `update`, `cover` (`image_base64`), `remove_cover`, `undo`; see [mcp.md](mcp.md).
