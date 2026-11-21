# Discover hub (`/library/discover`)

The page that gathers what the library suggests next, built from the same sections as [`GET /api/library/discover`](../api/discover.md). Part of the library (Standard and Full; the analysis parts only in Full). A chip on `/library` and a *Discover* entry under Library in the menu lead to it.

## Code

| Piece | Role |
|---|---|
| `Domain\Library\Rediscover` | The six rediscover shelves, one aggregate query each over `plays` (see the API doc for the rules). `select()` returns ids with the numbers behind the reason and keeps them `discover.cache_seconds`. |
| `Domain\Library\Discover` | `sections($hidden, $only, $limit, $catalogue, $seed)`: the rediscover shelves plus `new_releases` (`CatalogueItems::newReleases`), `mixes_well` (`MixesWith` from the analysed track played last), `listening_modes` (`ListeningModes::available`) and `random_albums`; items shaped like the API (`ApiItems`) plus a `reason`; empty sections dropped. The one source of the API and the page. |
| `Api\DiscoverController` | `GET /api/library/discover`: validation, scope and client, calls `Discover::sections`. |
| `Livewire\DiscoverHub` + `livewire/discover-hub.blade.php` | The page. Renders every section **by its `kind`**, so a new section needs no change here. |
| `config/discover.php` | Every threshold: items per shelf, stale days, minimum play days / plays, the on-this-day window, new-release days, seed window of `mixes_well`. |

## What the page shows

In order: *New from your artists* (catalogue cards, linking to the artist page where the Discography card offers Want, Add and Play), the rediscover shelves (*Forgotten favorites*, *You used to play these*, *Around this day*, *Artists you left behind*, *Songs you loved*, *Not played in a year*), *Mixes well with {track}* (Full), *Listening modes* (Full) and *Picked for you*. A section with nothing is not drawn; with nothing at all the page says "Nothing to rediscover yet".

- **Albums and artists** are `<x-shelf>`s of `<x-media-card>`s with the shelf's one-sentence `subtitle` under its heading and the item's `reason` as the card's third line (`note`); a round cover for artists; quick play on the pinned player (`albums.play`, `artists.play`).
- **Songs** are the compact rows of the *Recently played* block of `/library` (artwork, title, artist, the reason in muted text, play on hover and always on touch); play goes to the player pinned in this browser through `LibraryPlayback::playTrack`, or says "Pick a player in the bar below first".
- **Listening modes** are the pill buttons of `/library` posting to `library.listening-modes.play`.
- **Picked for you** has *Another pick*, which only changes the seed of this shelf (a Livewire call), so the other shelves stay as they are.
- Scope: the leading source (`?scope=` overrides, as on `/library`); the picks of Mixes well and the modes read the Local files, which are the only ones analysed.

Shelves use `<x-shelf subtitle>` and `<x-media-card note>`, two optional props added for this page ([components.md](components.md)). No new element otherwise (DESIGN.md §7).

## Slot for similar tracks

`Discover::sections()` has a marked `SLOT similar_tracks` where the similar-tracks workstream adds its section once `features.similar_tracks` exists: one more entry of the same shape (`key`, `title`, `description`, `kind: "track"`, `items` with `reason`). The API lists it and this page draws it as soon as it is in the list; nothing else changes. Add its title to `Discover::titles()`.

## Tests

`tests/Feature/Library/DiscoverTest.php`: each query on SQLite (thresholds, favorites against listening, the one-day album, on this day with the window, the leap day, plays without a track, the scope), the cache, the fixed query count however many items there are, the API (sections, empty ones left out, validation, seed, new releases and the client token, mixes, profiles), the MCP tool and the page (render, empty, play, another pick, nav and chip).
