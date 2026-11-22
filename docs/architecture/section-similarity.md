# Similarity by section (workstream 16.5)

"Which sections of other tracks sound like THIS section?" It uses the Discogs-EffNet embedding that "similar tracks" ([similar-tracks.md](similar-tracks.md)) already uses, but one vector per section instead of one per track. **On demand only**: a track gets section vectors when someone asks (deep-analysis kind `section_sound`), so the pool you can search is exactly the tracks you analysed. That is the smallest version that works with the existing sidecars and keeps the "never across the library" rule; the consequence is stated in the UI.

## Smallest version

1. **Sections first.** Kind `section_sound` needs the stored sections of the track (kind `sections`, [structure-service.md](structure-service.md)). Asking for it without them is `422 sections_required` (API) or a failed job saying so; asking for `sections` and `section_sound` together queues sections first (one analysis worker, in order). Removing the sections removes the section sound (the boundaries changed); removing the sound leaves the sections.
2. **The sidecar** (`docker/analysis`, ML build) has a new endpoint `POST /section-embeddings`: multipart `audio` (mono 16 kHz WAV) and `sections` (a JSON list of `[start, end]` seconds, at most 64). It runs the effnet model once, **keeps the per-patch embeddings** `/analyze` averages away, and answers the mean over the patches that fall in each range (`docker/analysis/curve.py`, same patch-centre assumption as [mood-curve.md](mood-curve.md)):
   ```json
   { "version": 1, "model": "discogs-effnet-bs64-1", "duration": 212.4,
     "sections": [ { "start": 0.0, "end": 23.1, "embedding": [ ...1280 numbers... ] } ] }
   ```
   `503` without the effnet model, `422` for bad ranges.
3. **Storage:** table `section_embeddings` (migration `2026_11_23_100000`; `id`, `file_id` cascade, `position` = index in `file_analyses.sections.items`, `start`, `end`, `label`, `model`, `vector` L2-normalised little-endian float32 packed by `Embeddings::pack`, `content_hash` = `files.hash` when computed; unique `(file_id, position)`), written by `App\Domain\Similar\SectionEmbeddings::store()` (replaces all rows of the file; one unusable vector refuses the lot). Manifest `{ model, items: [{start, end}] }` in `file_analyses.section_sound`, stage `section_sound` (`SectionSoundStage`, version `1`). About 5 kB per section: a track with 8 sections is 40 kB, so 1,000 analysed tracks are 40 MB. A vector whose `content_hash` differs from the file's current `hash`, or whose `model` is not `Embeddings::MODEL`, is ignored (the same rule as `file_embeddings`).
4. **The query** (`App\Domain\Library\SimilarSections`): the chosen section's vector against every stored section vector of every other track in scope (`LibrarySources::tracks`, scope and client hiding as for similar tracks), exact dot products in PHP (`SimilarSections` streams rows in chunks of 500; about 50 µs per section), the **best section of each other track**, most alike first, at most `analysis.section_matches_max` (25). Never the track itself nor the same song by the same artist (same `name_key`) on another release. No cache and no sqlite-vec: the table only holds analysed tracks, so a scan is milliseconds to a second. If someone analyses thousands of tracks, the engine of `SimilarTracks` (`similar.engine`) is the place to extend; not built.

## API, MCP, UI

`GET /api/library/tracks/{id}/similar-sections?section=&limit=&scope=&client=` ([../api/section-similarity.md](../api/section-similarity.md)), MCP `deep_analysis` action `similar_sections`, and the album page's Track details row **Sections like** (`Livewire\SectionSimilar`: a section picker, matches as rows linking to the album with the matched time range and the similarity). Playing a matched section (seeking into the other track) is not built: the rows link to the album.

## Left out, and why

- **Searching the whole library by section** would need section vectors for every track (6-10 times the storage of `file_embeddings`, and the structure service plus the sidecar for each file): out of scope by the "on demand only" decision, and the roadmap said to measure before committing. The pool grows as tracks are analysed.
- **Cross-track "this chorus resembles that chorus" ranking quality** is unmeasured: whether the genre-trained Discogs-EffNet vector separates sections of one song or only genres is the open question of the plan. The first real check: analyse three or four tracks of one artist and see whether sections of the same kind (quiet intro vs. loud chorus) match across tracks.

## Verified and not

Verified against a faked sidecar: the stage, storage rules (changed file, wrong count, bad vector), removal cascade, the query (best per track, order, exclusions), API, MCP and UI states. **Not verified:** the real `/section-embeddings` endpoint on a real model (see the unverified note in [mood-curve.md](mood-curve.md)), match quality (above), time per track on small hardware.
