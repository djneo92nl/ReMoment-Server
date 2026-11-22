# Related works in the web UI

`Livewire\TrackRelated` (`app/Livewire/TrackRelated.php`, `resources/views/livewire/track-related.blade.php`): the **Related** row of a track's *Track details* panel on the album page (`resources/views/albums/show.blade.php`), under Similar. Data, shapes and the MusicBrainz mapping: [../api/related-works.md](../api/related-works.md).

- Shown for a track with a MusicBrainz recording id (`mbid`); lazy like the Sections, Stems and Similar rows. Library scope is the leading source.
- One line per item: the relation as a small label ("Cover of", "Remix of", "Is quoted in" …), then the title. A related track the library holds is a link to its album page (`#track-{id}`), anything else is plain text with the artist (a cover: who wrote the song) and the disambiguation in brackets.
- **Other versions here**: the library tracks that perform the same MusicBrainz work as this one (a cover and its original), as links.
- Nothing found: "Nothing related found on MusicBrainz." Built from the existing text and list styles only (no new UI element).

Tests: `tests/Feature/Library/RelatedWorksTest.php`.
