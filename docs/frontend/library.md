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


### New from your artists

A shelf on `/library` (between the browse shelves and Wishlist; `<x-shelf>` of `<x-media-card>`s, up to 20): albums and EPs of your artists released in the last 90 days or still to come that the library lacks, each linking to the artist page (where the Discography card offers Want, Add or Play). Subtitle: artist and date, or "coming 12 Nov". Left out entirely when there is nothing.


### Collection (`/library/collection`)

`Livewire\PhysicalCollection`: the physical shelf. An *Add to the collection* card (a barcode finds releases on MusicBrainz, "Use this" fills the form; or type it by hand for tapes and bootlegs: artist, title, year, medium, where it is), filters (search, medium, ripped), and one row per item with the album link when the library has it, a *Ripped* badge, **Mark ripped** and **Remove**. A chip on `/library` links to it. Owning an item shows `Owned` on that album's release-group in the artist's Discography card and closes a wish.

### Streaming extends the library (Spotify)

The library is the local tracks. A streaming service **extends** it live: nothing of it is imported, so there is no "Add to library" and no favorite on a streaming track (favorites are local). What you can do with it is **play** and **want** (the wishlist, `wanted_tracks`, source `streaming`).

- **Artist page, Spotify side** (`/artists/{id}?source=spotify`, the Spotify button; `Livewire\ExternalArtist` embedded): what Spotify has for the artist, laid out like the local page: *Play on Spotify / Shuffle*, **Popular** tracks (Last.fm's top tracks matched on Spotify when a Last.fm key is set, else Spotify's search for the artist's tracks; Spotify's own top-tracks endpoint is gone) with play and a star to want, and **every album and single** as cards (each opens the album, with a play button; "In library" when the library has it). The library's own list of Spotify-sourced albums is not shown. The Spotify artist id is found by exact name and remembered as `spotify_id` metadata.
- **Album page, Spotify side** (`/albums/{id}?source=spotify`, `Livewire\AlbumMoreOnSpotify`): the album as Spotify has it, in order, so a local album that lacks tracks can still be played whole; tracks the library has are marked, each track has a play button and a star.
- **`/external/spotify/artists/{id}` and `/albums/{id}`**: the same views for any artist or album, also ones the library lacks (reached from the search box). Album page: cover, Play / Shuffle, "In your library" when it is, the tracks with play and star.
- **Search box** (`Livewire\GlobalSearch`): a *From Spotify* block under the library's results: artists, albums and tracks, each playable on the player pinned in this browser (`PlaysExternal`; a note when none is pinned), tracks with the star; names open the pages above.
- **Discography card** (artist page): an entry only a service has shows **Play on Spotify** instead of an add button.
- **Playlists** (`/playlists`): a *Local | Streaming* filter. Local lists the playlists made here (normal and smart; a Spotify playlist row an earlier sync imported is left out). **Streaming** (`?source=streaming`, `Livewire\ExternalPlaylists`) reads the account's Spotify playlists live (cards with a play button). A playlist opens at `/external/spotify/playlists/{id}` (`Livewire\ExternalPlaylist`): Play / Shuffle, and the tracks with play and a star to want. Spotify only shares the tracks of playlists you own or collaborate on since February 2026, so a followed playlist says so and still plays as a whole. Nothing is imported and a playlist is never copied to local.

### Streaming scope of `/library`

The Streaming scope of the library page (`/library?scope=streaming`; the old `/library/streaming` redirects there) adds your Spotify as three shelves in the library's own style, above the DLNA shelves; nothing is stored. Each shelf loads on its own, so a slow or refused one never holds up the rest: **Liked songs on Spotify**, **Saved albums on Spotify** and **Recently played on Spotify** (`Livewire\StreamingSection`: cards, 20 per shelf, with a play button on the cover, a star to want on tracks and "In your library" on the ones you have; albums open `/external/spotify/albums/{id}`). A section with nothing or a refusal is one quiet line. Liked songs are not favorites: favorites are local only. Spotify **playlists are not on this page**: they have their own page (`/playlists`, Streaming). Recently played needs the `user-read-recently-played` scope, requested on connect; an older connection shows "Reconnect Spotify under Settings". New releases of followed artists are not offered: Spotify's browse and follow endpoints are gone; the *New from your artists* shelf covers your own artists through MusicBrainz.
