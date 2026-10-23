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
- `GET /library/tracks` (`library.tracks`): every track of the scope, 100 per page, in the Livewire `TrackBrowser`: search `q`, sort (the smart playlist sorts except random), a Favorites switch, and a Filters panel with the same field / operator / value rules as a smart playlist (`SmartFields`, `match` all/any), all kept in the URL. With rules set, "Save these rules as a smart playlist" (name, sort, limit) creates one through `PlaylistEditor::create` + `setRules`; the search text and Favorites switch are not rules and are not saved. Chip and count on the hub.
- `POST /library/tracks/shuffle` (`library.tracks.shuffle`): a random sample of up to `LibraryController::SHUFFLE_TRACKS` (100) tracks of the page's selection (all tracks, or the posted `match`/`rules`/`q`/`fav`, via `TrackBrowse::query`) that the pinned player can play (`LibraryPlayback::playableTracks`), queued shuffled through `playTracks`. Same REST path as `POST /api/devices/{id}/library/play-tracks`.
- `GET /library/favorites` (`library.favorites`): favourite albums and artists of the scope.

## Not on the page any more
The old "Recently played" track list (it listed plays of tracks that are not in the library too) is replaced by the shelf of albums/playlists/stations. Text-only plays are in `/history`.

## Album page: `App\Domain\Library\AlbumPage::build($album, $source, $scope, $pinned)`
`AlbumController@show` loads the tracks (metadata, analysis, plays count) and `AlbumPage` assembles the rest: tracks in album order for the **selected source**, the album's sources, meta line, genres/tags, sound, credits, play stats, and `playable` (track id => can the pinned player play it, the two library paths checked once per album, never per track). The page's query count does not depend on the number of tracks (`AlbumPageTest`).

- **Source**: `?source=dlna|spotify` when the album has it, else the scope's (`local` → dlna, `streaming` → spotify), else the leading source, else local before streaming. The tracklist, strip, meta line, Play/Shuffle (`albums.play?source=`, `LibraryPlayback::playAlbum(source:)` limits playback to that path and its tracks; a device that can't play it gets `NotPlayableException`) and add-to-playlist all follow it.
- **Track favourites**: `tracks.favorited_at`, `POST /tracks/{track}/favorite` (`tracks.favorite`), a Tracks section on `/library/favorites`. One record per song across sources, so a favourite shows in every source view. Web only for now: REST/MCP parity (`PUT /api/library/tracks/{id}/favorite`, tracks in `GET /api/library/favorites`) is a follow-up so API clients are not changed silently.
- **Add to playlist**: `POST /playlists/{playlist}/tracks` with `track_id`, or `album_id` + `source` (the album's tracks of that source, in album order); only playlists made here (`Playlist::isEditable()`), duplicates ignored (`PlaylistEditor::addTracks`). `POST /playlists` accepts the same fields to create a playlist that starts with them.
- **Queue** (play next / add to queue) has a reserved place next to Add to playlist; it renders (album page: "Play next" and "Add to queue", on the pinned player) when that device reports `queue_next` / `queue_add`; both post to `devices.queue.add` (`Http\Controllers\QueueController`, shared track resolution in `Domain\Library\QueueTracks`). The same entries (`<x-queue-menu-items type id label>`, rendered inside `x-menu`) are in the "…" menu of track rows on the album page, the artist page's top tracks and the playlist page's tracks, in the artist hero's "More" menu (`type=artist`) and in the playlist page's Playback card (`type=playlist`).

