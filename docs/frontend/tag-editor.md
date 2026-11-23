# Tag editor (web)

Workstream 8.4; behaviour in [../architecture/tag-editor.md](../architecture/tag-editor.md). Admin login only (under `/settings`), Standard and Full profiles.

## One file: `Livewire\StorageFiles` dialog

`/settings/storage` › Files › a file's `…` menu › **Edit tags…**. The dialog reads the tags **from the file** (`TagEditor::read`), so what you see is what is written. It has:

- the eight fields; **only the fields you changed are written** (clear a field to remove it from the file); "Nothing changed." when nothing differs;
- the **What could this be?** pane (library and MusicBrainz candidates fill the fields; nothing is written until Write tags);
- a **Cover** block: whether the file has an embedded cover, a picker for a JPEG or PNG (a preview shows the pending picture; checked for type, size and at least 100 px), **Remove the cover**;
- **Undo the last edit** (left of Cancel) while the last edit can be undone;
- a warning and a disabled **Write tags** when the file cannot be edited (inbox, a format that cannot be written, no ffmpeg).

The menu also has **Edit the whole album…** for a file that has a track with an album.

## One album: `/settings/storage/albums/{album}` (`Livewire\AlbumTagEditor`)

- Album fields (album, album artist, year, genre) written into every file. A field the files disagree on is empty with the placeholder "Differs per file" and is written only when typed in.
- A table of the album's own files: disc, number, title, artist per file (read only for files that cannot be edited, with a "read only" badge).
- A cover picker: the picture is embedded in every file and becomes the album's picture.
- **What could this album be?** (`Domain\Storage\AlbumSuggestions`, runs once the page has rendered and on Search): MusicBrainz releases for the artist and album (editable hints, defaults from the album record), and the artist's known albums (marked "in your library"), each with its Cover Art Archive picture. **Use** fills album, album artist and year and picks the cover; **Cover only** picks just the picture. The picked cover is fetched on Save (only from coverartarchive.org); an upload overrides it. Needs network; an outage shows as a message. Unverified against live MusicBrainz data.
- **Search for artwork** (Cover card): searches releases again for the album and album artist *as typed in the form now* (no reload), shows their covers as a grid; click one to use it.
- "Move the files to the library layout for their new tags" (on by default).
- **Save**, and **Undo the last edit** while any file of the album has one. The result line says how many files were rewritten, skipped (inbox or unsupported) or failed with the reason; the page follows the album when its name changed.

No new components: the existing `x-card`, `x-text-input`, `x-toggle`, `x-secondary-button` and `x-primary-button`, `x-alert`, `x-badge`, and the dashed upload label of the artist settings.

## From the album page

When logged in as admin (Standard and Full), `/albums/{id}` has **Edit tags & cover…** in the hero's "…" menu (opens the album editor above). An album without a picture shows **Why this album has no cover** at the top of that editor only (`Domain\Library\AlbumArtworkDiagnosis`: no own files / no MusicBrainz release matched / details not fetched yet / no front cover on the Cover Art Archive).
