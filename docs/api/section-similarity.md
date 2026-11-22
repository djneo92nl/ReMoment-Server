# Sections that sound like this one (`GET /api/library/tracks/{id}/similar-sections`)

Workstream 16.5, design in [../architecture/section-similarity.md](../architecture/section-similarity.md). Open like the library (no token), **Full profile** (`422 not_available_in_profile` elsewhere). The track is a library track id.

```
GET /api/library/tracks/{id}/similar-sections?section=1&limit=10&scope=local&client=…
```

| Parameter | Meaning |
|---|---|
| `section` | Index of the section (position in the track's `sections`); default the first section with a sound vector |
| `limit` | 1-25, default 10 |
| `scope`, `client` | As on `GET /library/tracks/{id}/similar` (hidden libraries) |

```json
{ "canonical_id": 42,
  "section": { "index": 1, "start": 23.1, "end": 51.4, "label": "B" },
  "sections": [ { "index": 0, "start": 0.0, "end": 23.1, "label": "A" }, { "index": 1, … } ],
  "data": [ { "track": { …same item as /library/search tracks… },
              "section": { "index": 3, "start": 80.2, "end": 112.0, "label": "A" },
              "similarity": 0.91 } ] }
```

- `similarity` is the cosine similarity (0-1 in practice) of the two sections' Discogs-EffNet vectors, most alike first; one entry per other track (its best section). Never the track itself, nor the same song by the same artist.
- **The candidates are only tracks whose section sound was analysed** (deep-analysis kind `section_sound`, requested per track after `sections`, see [deep-analysis.md](deep-analysis.md)); the search never covers the whole library. An empty `data` means "no analysed track has a section that sounds like it".
- `sections` lists the sections of this track that have a vector, for a picker.

| Status | Body | Meaning |
|---|---|---|
| `422` | `{ "error": "not_analysed", "message": …, "state": "none" }` | This track has no (current) section sound; `state` is the `section_sound` state (`none`, `queued`, `running`, `failed`). Request `sections` and `section_sound` first |
| `404` | `{ "error": "unknown_section" }` | No such section index |
| `422` | validation | `limit` out of range, unknown `scope` |

MCP: `deep_analysis` action `similar_sections` (`track_id`, `section`, `limit`).
