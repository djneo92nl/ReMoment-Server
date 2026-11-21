# Similar tracks in the web UI

`Livewire\TrackSimilar` (`app/Livewire/TrackSimilar.php`, `resources/views/livewire/track-similar.blade.php`) lists what sounds like one library track and starts a radio from it. Domain code and API: [../architecture/similar-tracks.md](../architecture/similar-tracks.md), [../api/similar-tracks.md](../api/similar-tracks.md).

## Where it is

- **Album page**, a track's *Track details* panel, a **Similar** row under Stems (lazy; only on servers where `Embeddings::any()` and the Full profile, and for own-file tracks with a waveform like the Sections and Stems rows). Six rows, no heading.
- **Discover hub and any other page:** the piece is self-contained and takes the track by id:

```blade
<livewire:track-similar :track-id="$track->id" :limit="12" title="Because you played {{ $track->name }}" />
```

  Props: `track-id` (required), `limit` (1 to 50, default 8), `title` (default "Sounds like this"; `""` leaves the heading out). It renders nothing useful outside the Full profile and says "No sound data for this track yet." for a track without an embedding, so callers need not check first (check `features.similar_tracks` / `Embeddings::any()` to decide whether to place it at all).
- **A hub section built in PHP instead** (the Discover hub's `track` sections): `(new SimilarTracks($seed, $hidden))->items($limit)` returns `ApiItems::track` rows plus `similarity`, `distance` and a `reason` sentence ("Sounds like {track} by {artist}"), which is the shape `Discover::sections()` already draws; the hub's `SLOT similar_tracks` takes `$sections[] = ...` of that.

## Behaviour

- Each row: cover, title, artist, similarity as a percentage (the cosine similarity of the sound, not a probability), a play button.
- **Play** plays that one track on the player pinned in the sticky bar (`SelectedDevice`, the cookie); **Play similar** queues the track and its nearest neighbours there (the same queue as `POST /api/devices/{id}/library/play-similar`). With no pinned player both say "Pick a player in the bar below first."; a player that cannot play enough of them says so.
- Scope is the leading source (`LibrarySources::hiddenForRequest(null)`); results are the cached ones of the REST list.

Tests: `tests/Feature/Library/SimilarTracksUiTest.php`.
