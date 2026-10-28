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

## Discography and wishlist

`App\Livewire\Catalogue` (`resources/views/livewire/catalogue.blade.php`) is one component for two places, built like "More on Spotify" (a card with a section label, compact cards in `<x-album-grid>`, "Show all"):

- **Artist page** (`/artists/{id}`, Overview tab, below Albums; only when the artist has MusicBrainz release-groups, so a library that never ran `catalogue:sync` shows nothing new): the *Discography* card with filter pills (All, Not in your library, Wished, each with its count), one card per release-group: cover (the library album's, else the MusicBrainz cover through the artwork pipeline), title, year (· EP), badges (`Owned`, `In your library`, `On Spotify`, `Not in your library`, `Wished`, `Hidden`), a line "Plays on Kitchen, Study" from `LibrarySources::playableOn` for the visible devices (or "No player can play this yet"), and the actions **Want** (the wishlist is for albums you want to buy or rip), **Add to library** (a Spotify answer, Spotify connected; queues `ImportSpotifyAlbumById`) and **Not interested** (hidden; "Show the ones I'm not interested in" brings them back with **Show again**). A linked entry links to its album page. Twelve entries show before "Show all N entries". The card polls every 6 s only while a Spotify lookup is still pending.
- **Wishlist** (`/library/wishlist`, `library.wishlist`): the same component without an artist: wished and not yet owned albums (50 most recently wished), with the artist's name on each card. `/library` shows a *Wishlist* shelf of `<x-media-card>`s (up to 20, linking to the artist page) only while something is wished for.

Wish and "not interested" are household-wide, guest accessible like favourites; the component calls the same rules as `PUT /api/library/catalogue/{id}/wanted|ignored` (`docs/api/availability.md`). Playing a streamable-only entry needs the generic play-external path (design PR 1b) and has no button yet.

### Songs from the history

Each track row of `/history` (`Livewire\PlayHistory`, radio tracks included) has a heart and a star next to Match: the **heart** favorites the song (a play only logged as text, or a radio station's announcement, is first put in the library with `PlayedTrackAdder::addFromPlay`: Spotify match when connected, else the play's artist, title and album), the **star** wishes for it ("Want to buy or rip this song", `wanted_tracks`, filled star when it is on the wishlist) without adding it to the library. Wished songs show as a *Songs* list at the top of `/library/wishlist` and in the Wishlist shelf of `/library`.

