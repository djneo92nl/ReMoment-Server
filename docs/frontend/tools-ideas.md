# More tools (plan, not built)

Ideas for the Tools menu ([tools-menu.md](tools-menu.md)). A tool is a page that *does* something with the library, the sound or the house, not a browsing page. Every idea below reuses code and endpoints that exist; the work is mostly UI. Today there are two tools (Mixer, Export); the DJ mode ([dj-mode.md](dj-mode.md)) grows inside the Mixer and is not repeated here.

Rules every tool follows (from CLAUDE.md):

- **One admin, no accounts.** A tool is open to guests to look at; anything that writes files, settings or the library needs the admin login (session) or the admin bearer token, like Export. No per-user state.
- **API first.** A tool calls the REST API (or the same domain class the API uses). When it needs a new endpoint, add it to `docs/api/openapi.yaml` and an action to the MCP tool in `app/Mcp/Tools/` and `docs/api/mcp.md`.
- **Profiles.** Pick an existing `feature` of `config/profile.php`; a new route goes in that row. Control has no tools today and stays that way unless a tool needs only devices.
- **Livewire stays.** No JS framework; client JS only as thin glue (the mixer is the exception because it is audio).

Sizes: S = a day or less, M = a few days, L = a week or more.

## Groups on the hub

`/tools` is one flat list now. With ten or more tools, add an optional `group` key to `Tools::all()` and show the groups as sections on the hub and as headings in the sub-menu:

- **Sound**: Mixer, Set builder, Loudness matcher, Stem practice, Stem export
- **Library**: Library cleanup, Tag and cover fixer, Import, Export, Wishlist and new releases
- **Home**: Announce, Sleep and wake, Room matcher, Party launcher, Tap and tag printer
- **Insight**: Year in review

A tool without `group` lands under "Other", so the change is backwards compatible. A test checks that every group is one of these names.

## Build order

1. Library cleanup (most daily value, no new backend)
2. Set builder (fits the Mixer direction)
3. Loudness matcher and Announce (small)
4. The rest by appetite; Tag and cover fixer last (writes files, needs the most care)

## Sound

### Set builder *[M; Full, feature `deep_analysis`]*

Pick a start track and an arc (warm-up, peak, cool-down, or a BPM/energy range and a length); the tool proposes an ordered list where each step is a good mix from the one before.

- **Candidates** per step from `Domain\Library\MixesWith` (tempo within 6 %, also half/double; Camelot same, relative or adjacent), narrowed by the arc: `arousal` and `bpm` target per position, sound filters of `GET /api/library/tracks` (`docs/api/sound-query.md`).
- **Order** is a greedy walk with a small look-ahead: score = key relation, tempo difference, distance to the arc's target at that position, a penalty for the same artist twice in a row and for repeating a track. Deterministic for the same input (seeded), with a "Re-roll" that changes the seed.
- **Result** is a list the user can reorder, swap a step ("another one here" shows the next three candidates), then: save as playlist (`PlaylistEditor`), play on a device (`play-playlist`), or open pairs in the Mixer (`/mixer?a=&b=` for the step and the next).
- **Honest limits:** tracks without analysis (no BPM or key) are not offered; the page says how many tracks are left out and links to `/settings/analysis`. A set is only as good as the analysis.
- **API/MCP:** `GET /api/library/set-suggestions?start=&arc=&length=&seed=` (open) and a `browse_library` kind or a `set_builder` MCP action.
- **Tests:** ordering rules on fixtures with known BPM/key, determinism per seed, tracks without analysis excluded.

### Loudness matcher *[S to M; Full, feature `analysis`]*

One place to see and use the loudness data.

- **View:** spread of loudness (LUFS/ReplayGain) and PLR for an album, playlist, or the whole library: a histogram, the quietest and loudest tracks, "albums that will jump the most" when played in a mix.
- **Preview** what playback loudness normalisation would change (per track gain, never past the stored true peak, as `PlaybackLoudness` already limits it), without switching it on.
- **Controls** (admin): the `playback_loudness` setting (`GET|PUT /api/admin/storage/playback-loudness`) and a link to the Export dialog's `loudness` option, so the three share one explanation.
- **No new analysis**: reads what the loudness stage stored. Tracks not analysed are counted and linked to the backlog.
- **Tests:** histogram buckets and gain preview on fixtures; the clamp at the true peak.

### Stem practice (karaoke) *[S to M; Full, feature `deep_analysis`]*

One track, one deck of the mixer engine: vocals muted (or only vocals), a tempo slider with pitch kept, a key shift, and a loop picked from the sections lane. For singers and instrumentalists.

- Reuses `resources/js/mixer/engine.js` with a single deck and the kept stems (`GET /api/library/tracks/{id}/stems`); a track without kept stems gets the existing "Analyse stems" action.
- Loop points are track time on the buffer sources (see dj-mode D3), so this tool needs only the loop part of D3, not the beat grid. Build it after D3 or share that code.
- Needs https for the key shift on a LAN, like the mixer (see dj-mode "Prerequisite").
- **Tests:** the page and its states (no stems, working stems); the loop maths in `tests/Js`.

### Stem export *[S; Full, feature `deep_analysis`]*

Download a track's kept stems, or one section of it, as audio files, for remixing or practice.

- Uses the signed, Range-capable stem URLs (`docs/api/stems.md`); no new storage.
- **Section cut** (start and end from the sections lane) is done in the browser with the decoded buffers and written as WAV (small, lossless); full stems are plain downloads of the kept MP3/FLAC.
- Nothing is stored on the server; the signed URL's lifetime already limits sharing. Say plainly that the files are for the owner's own use.
- **Tests:** the page lists the four stems and the signed links for a track with stems; the WAV writer in `tests/Js`.

## Library

### Library cleanup *[M; Standard and Full, feature `library_health`]*

A guided wizard over the health reports (`GET /api/library/health/{report}`, `docs/api/library-health.md`): duplicates, suspected transcodes, damaged, clipping, lossy, incomplete albums, orphans, and covers.

- **One finding at a time**, most certain first, with the reason the report gives and a preview (cover, file facts, both copies side by side for duplicates).
- **Actions per report, one click each** (admin; a guest sees the findings read-only):
  - duplicates: keep the best copy (by format, bitrate, loudness, clipping), the other goes to the trash folder, never a hard delete; the physical-collection link stays;
  - covers: re-fetch from Cover Art Archive (`library:extract-covers`, enrichment job) or keep the embedded one;
  - incomplete album: add the missing tracks to the wishlist (`wanted_tracks`);
  - suspected transcode or damaged: mark as "ignore", or put on a "to replace" list the wishlist shows.
- **Undo for the last action** where possible (restore from the trash folder); anything else says it cannot be undone before it runs.
- **Progress** ("37 of 120 findings handled") and a "skip" that remembers per finding id so the wizard does not nag.
- **Needs a trash step** if the storage layer has none; check `docs/architecture/file-storage.md` before building and add a `storage trash` with retention first.
- **Tests:** each action's effect on fixtures, the guest read-only mode, skip memory.

### Tag and cover fixer *[L; Standard and Full, feature `file_storage`]*

Edit an album's tags in bulk and write them to the files.

- **Bulk edit** artist, album artist, album, year, track and disc numbers, with a preview of every change before writing; a cover chooser (MusicBrainz release, Cover Art Archive, upload).
- **Writes to the files** (admin only, confirm with a count). The file's fingerprint changes, so the indexer must re-read it, and the one record per artist/album/track rule (`LibraryIdentity`) must be applied to the new names without making duplicates (reuse `LibraryMerger` rules; run the merge dry-run afterwards).
- **Risks to settle in the design before building:** file locking while a device plays it, S3 locations (rewrite means upload again), tag formats per container (ID3v2, Vorbis, MP4), and a backup of the original tags (a sidecar JSON per file, kept for a retention period) so a bad edit can be reversed.
- Mark `manual` as the metadata source (as artist settings do) so enrichment does not overwrite the edit.
- **Tests:** tag round-trip per format on small fixture files, preview equals result, backup restore.

### Import *[M; Standard and Full, feature `file_storage`]*

A page for the inbox: drop files, see what is there, and what `library:import` would do before it runs.

- Lists inbox files with the artist/album/track the organizer would use and the target path; flags duplicates of tracks already in the library (name key and duration, as `LibraryIdentity` finds them) and files that could not be read.
- Runs the existing queued import on the chosen items, shows progress in the task tray (`UserTasks`).
- Later the CD ripper (node role `ripper`, not built) feeds the same inbox; the page needs no change for that.
- Reuses what Settings › Files already has (upload, inbox import); this tool is the preview and the guidance, and Settings stays.
- **Tests:** preview equals the organizer's real paths; duplicate flag; the guest sees nothing writable.

### Wishlist and new releases *[S; Standard and Full, feature `availability`]*

One page for what you want and what is coming.

- Tabs: **Wanted** (`GET /api/library/wishlist`, albums and songs), **New releases** (`GET /api/library/new-releases?days=`), **Missing from my artists** (discography `availability=missing`).
- Per row: want, ignore, "I own this physically" (creates a `physical_items` row, which closes the wish), and play on Spotify when streamable (`play-external`).
- Closed to client tokens like the endpoints (`403`).
- **Tests:** each action calls the existing endpoint; a fulfilled wish moves out of Wanted.

## Home

### Announce *[S; Standard and Full, feature `announcements`]*

Pick a clip (or upload one) and the rooms, set a volume, and send it. The device's playback comes back afterwards.

- Wraps `POST /api/announcements`, `GET /api/announcements/clips` and `Announcer` as they are (`docs/api/announcements.md`); only devices with `stream_url`, and the page greys out the rest with the reason.
- Text to speech is **not** part of the first version (no engine chosen; it would add a dependency and a licence question). Phase 2 may add a local engine (Piper) as one more clip source, decided separately.
- Status is shown (`Announcer` remembers what played); an announcement that could not restore says so.
- **Tests:** device filter, volume pass-through, restore state shown. Unverified on hardware, as the layer is.

### Sleep and wake *[M; Standard and Full, feature `scenes`]*

One screen for scheduled listening, built from scenes rather than a new engine.

- **Wake:** a time and days, a room, what to play (radio station, album, playlist, a random album) and a volume ramp from low to the target over N minutes. Saved as a scene with a `schedule_time`/`schedule_days` (`scenes-run-due` already runs it) with `delay` and `set volume` steps.
- **Sleep:** start a sleep timer now (`sleep_timer` capability), or a fade-out over N minutes then standby, as a scene run on demand.
- The page edits the same scene rows `/scenes` does (tagged so they show as "wake" or "sleep"), so nothing is stored twice; `/scenes` stays the general editor.
- A scene's volume steps respect `volume_limits` and quiet hours (the API already clamps them).
- **Not built yet and needed:** a volume ramp operation (or a series of steps with delays) in `config/scene-operations.php`; check that first.
- **Tests:** the created scene's steps and schedule; the ramp stays under the limit.

### Room matcher *[M; Standard and Full, feature `multiroom`]*

Group speakers, set limits and calibrate sync, then save it as a preset.

- Pick rooms; per room a volume limit (`PUT /api/admin/devices/{id}/volume-limits`) and, for sync groups, an offset in ms (`docs/api/sync-groups.md`) with a "tap to nudge" +/-50 ms control and a test track.
- Native multiroom (ASE/Sonos) groups use `MultiroomPreset`; sync groups (fake multiroom) use `/api/sync-groups`; the page says which kind it makes and why (precision about +/-2 s for sync groups).
- Result: save as a multiroom preset or a scene step list (`AddToScene`).
- **Tests:** the right API per device kind; offsets saved; preset created.

### Party launcher *[S; Standard and Full, feature `library`]*

Switch on the guest queue for a device and show the QR code for `/party/{token}`; set the limits; end the party.

- Wraps the party card on `/devices/{id}/settings` (device meta `party`) as one page for all devices: a list of devices with an on/off switch, the QR code and a "projector" view (big QR and the queue) for a screen.
- Limits (adds per visitor, per address) are the existing ones; show them, change them where the settings allow.
- **Tests:** on/off sets the device meta; the QR links to the right token; guest read-only.

### Tap and tag printer *[S; Standard and Full, feature `library`]*

Pick an album, playlist, track, radio station or physical item and get what to print or write: a QR code (`/t/{uid}`) and the NFC payload.

- Wraps `/api/admin/tags` CRUD and `PlayTags`; a printable sheet of labels (album art, title, QR) as plain CSS print layout, no PDF engine.
- Shows the unknown uids the server kept for ten minutes (`404 unknown_tag`), so a scanned blank tag can be assigned on the spot.
- Admin only, as `/settings/tags` is; this tool is the friendlier front for it, and Settings stays.
- **Tests:** a created tag plays through `POST /api/tags/{uid}/play`; the unknown-uid list assigns.

## Insight

### Year in review *[M; Standard and Full, feature `stats`]*

A recap page from `GET /api/library/stats` and `/api/library/insights` for a chosen period: top artists, albums and tracks, hours listened, new against repeat, late-night listening, mood and tempo by part of the day, most skipped, first and last play of the year.

- A fixed, good-looking layout with sections that scroll; a "Print / save as PDF" view with print CSS only. No share links (no public pages; the app has no accounts and no public side).
- Analysis-based cards (mood, tempo) show only in Full, as `insights` already does.
- Insights reads are cached five minutes; the year view is cached per period for a day.
- **Needs:** a `from`/`to` period on the stats and insights reads (today they take `days`); check `docs/api/stats.md` and extend both with the same parameter pair, in `openapi.yaml` and MCP `listening_stats`, `listening_insights`.
- **Tests:** the period boundaries and timezone, empty periods.

## Open questions

1. **Trash for the cleanup tool:** does the storage layer have a trash with retention? If not, building it is the first step of Library cleanup.
2. **Text to speech for Announce:** none in v1; a local engine later, or never?
3. **Tag writes:** write to the files (with a sidecar backup), or only change the database (the file stays the truth, so the next scan would undo it)? The second is much simpler and almost useless; the first is the real tool and needs the risks above settled.
4. **Year in review output:** print/PDF only, or also a stored snapshot per year (a table) so a year stays the same after history retention prunes plays?
5. **Hub groups:** are the four group names right, and should the sub-menu show groups or stay flat?
