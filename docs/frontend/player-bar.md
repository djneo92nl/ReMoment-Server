# Sticky player bar

A fixed bottom row on every web page (`resources/views/layouts/app.blade.php`) showing the player pinned in this browser. Pinning one makes it the default target of the library play buttons.

## Selected device
- Cookie `remoment_player` (device id, forever), read through `App\Support\SelectedDevice` (scoped singleton: one lookup per request; a cookie for a deleted device is ignored).
- `POST /player/select/{device}` (`player.select`) pins, `DELETE /player` (`player.clear`) unpins. Unauthenticated like the play routes; there are no accounts, so the pin is per browser.

## Components
- `App\Livewire\PlayerBar` (`livewire/player-bar.blade.php`): artwork, title, play/pause, next, link to the device page, a button opening the `select-player` picker. Refreshes on MQTT push via `liveDevice(id)`; controls go through `SpotifyRouting::driverFor()` like `Nowplaying`.
- `<x-play-button>` (`components/play-button.blade.php`): replaces the old "button + `<x-device-picker>`" pair. When the pinned device is in the `devices` it was given (already filtered for what can play the item) it posts straight to `actionTemplate` with that id, plus a chevron (`elsewhere`, default on) that opens the picker; otherwise it opens the picker as before. The button's content is the slot, its classes the attributes.
- Used by albums (play, shuffle, track rows), artists, playlists, radio and "More on Spotify".
