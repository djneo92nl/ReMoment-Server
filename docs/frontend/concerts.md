# Concerts in the web UI

`Livewire\ConcertsShelf` (`app/Livewire/ConcertsShelf.php`, `resources/views/livewire/concerts-shelf.blade.php`): a **Coming up** shelf (`<x-shelf>`) of compact cards (`<x-card compact>`), one per upcoming concert: date and time, event name (a link to the MusicBrainz event), the artist when the shelf is not for one artist, venue and city. Data and rules: [../api/concerts.md](../api/concerts.md).

- **Artist page**: `<livewire:concerts-shelf :artist-id="$artist->id" />` in the Overview tab between the catalogue and the similar artists, Standard and Full only.
- **Discover hub** (and any page): `<livewire:concerts-shelf />` without an artist lists the concerts of every library artist, soonest first; the hub is not edited by this change, so adding that one line to `resources/views/livewire/discover-hub.blade.php` is the whole integration.
- The component renders an empty wrapper while the setting is off, nothing matches the location or the profile has no enrichment, so a page can include it unconditionally. It reads the `concerts` table only.
- The settings (switch and location text) are in the library form of Settings › Storage › Library (`x-text-input`, the checkbox row style of that form).

Tests: `tests/Feature/Library/ConcertsTest.php`.
