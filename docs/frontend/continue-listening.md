# Continue listening

`Livewire\ContinueListening` (`resources/views/livewire/continue-listening.blade.php`), embedded in `/library` under the chip row, above Recently played. Workstream 8.3; data and rules: [../api/playback-positions.md](../api/playback-positions.md).

- One section "Continue listening": compact rows like Recently played (cover, title, artist · album or podcast, a thin progress bar when the duration is known, `30:00 · 30:00 left`), at most 12, most recently kept first. **Renders nothing without a bookmark**, so a library of songs never shows it.
- **Resume** (play button) plays the item on the player pinned in this browser; the server seeks to the bookmark by itself (`ResumeOnPlay`). Without a pinned player a note asks to pick one. **Forget** (the ×, shown on hover, always on touch) clears the bookmark.
- Open to guests, like every play button (no login). Standard and Full profiles, because `/library` is.
- Episodes of a podcast appear here too, and play through the Podcasts page's player path ([podcasts.md](../api/podcasts.md)).
