# Library page (`/library`)

The listening hub, designed after the native app's Library screen (DESIGN.md §10). Server-rendered Blade, no Livewire.

## Data: `App\Domain\Library\LibraryHome::build($scope, $hidden)`
One call returns everything the view needs; every artwork cover is read from the cache in one round trip (`LibraryItemArtwork::preload`), and artist cards get their photo metadata eager loaded (`Artist::PHOTO_METADATA`), so the query count does not grow with the number of cards (`LibraryHomeTest` guards it).

| Key | Source |
|---|---|
| `hero` | scope name/icon, counts, newest covers (collage), first cover's tint |
| `recentPlayed` + `playedArtwork` | `RecentItems::get()`: albums, playlists and radio stations, newest first |
| `recentAdded`, `topRated` | albums (`topRated` via `LibraryOverview::topRated`, cards with `detail`) |
| `topArtists`, `similarArtists`, `favoriteArtists` | artists with album/play counts |
| `playlists` + `playlistArtwork` | playlists, recently played first (`PlaylistArtwork::choose`) |
| `genres` + `genreAlbums` | `LibraryOverview::topGenres` + `genreAlbumCounts` |
| `favoriteAlbums`, `counts`, `sound`, `glance` | favourites, chip counts, mood/tempo buckets, totals/decades/enrichment |

`RecentItems` and `RandomAlbum` are also the implementation behind `GET /api/library/recent-items` and `/random-album` (the API controller only formats their result); the JSON is unchanged.

## Routes
- `GET /library` (`library.index`): scope from `LeadingSource::forRequest`.
- `POST /library/shuffle` (`library.shuffle`): a random album of the scope (`RandomAlbum::pick`, preferring one the pinned player can play), played shuffled through `LibraryPlayback` on the player pinned in this browser; flashes success or error like the other web play buttons (`FlashesLibraryPlayback`). Without a pinned player the hero button opens the picker instead.
- `GET /library/favorites` (`library.favorites`): favourite albums and artists of the scope.

## Not on the page any more
The old "Recently played" track list (it listed plays of tracks that are not in the library too) is replaced by the shelf of albums/playlists/stations. Text-only plays are in `/history`.
