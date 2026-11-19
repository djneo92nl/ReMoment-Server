# Library picker

`Livewire\LibraryPicker` is a reusable two-column picker for any tool that needs a selection from the library. Export uses it first (`/tools/export`, `Livewire\ExportFromLibrary`).

- **Left:** tabs Artists, Albums, Tracks, Playlists (the `types` prop picks and orders them), a search box, a list with a round image for artists and a square one for the rest. A click on a row shows its details and opens what is under it: artist → albums → tracks, album → tracks, playlist → tracks. The `+` on a row adds or removes it without opening it. Lists are paged ("Show more"), scoped like the rest of the web (`LeadingSource` default scope, `LibrarySources`).
- **Right:** the detail card of the focused item (image, type, name, track count, rough size) above the basket of everything picked, with remove, clear, the total and a confirm button.

## Use it

```blade
<livewire:library-picker wire:model="picks" name="export" confirm-label="Choose export settings" :types="['artist','album','track','playlist']" />
```

- `picks` (modelable): a list of `{type: artist|album|track|playlist, id}`.
- The confirm button dispatches `library-picked` with `name` (the prop, to tell several pickers apart), `picks` and `trackIds`. The host listens with `#[On('library-picked')]` and checks `name`; see `ExportFromLibrary`.
- `App\Domain\Library\LibraryPicks` is the shared code: `normalize()` (known types, no duplicates, merged ids resolved by `IdAliases`), `describe()` (basket rows with name, subtitle, track count, image) and `trackIds()` (in export order: an artist by album, an album by disc and track number, a playlist in its order, each track once; it reuses `ExportSelection`).

Export: the picked track ids go to `ExportDialog` as `type=tracks`, so the estimate, profiles, loudness and limits are the existing ones. The size in the picker is only an estimate (`LibraryPicks::AVERAGE_MB` per track); the dialog shows the real figure.

Not built: a sort option per tab (lists are by name), and a picker for Spotify or other streaming items (the picker is the local library).

## Own files only

`:own-files-only="true"` shows only what has a present original audio file on this server (`LibraryPicks::ownFiles()`): artists, albums, playlists and tracks without one are hidden, and the album, artist and playlist counts and the basket's totals count only tracks with a file. The export page sets it, because DLNA and streaming-only tracks cannot be exported. Without it the picker follows the default library scope.
