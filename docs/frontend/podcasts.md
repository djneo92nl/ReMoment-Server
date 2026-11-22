# Podcasts page

`/library/podcasts` (`Livewire\PodcastLibrary`, view `livewire/podcast-library.blade.php`, page `library/podcasts.blade.php`), reached from the **Podcasts** chip on `/library` (with the subscription count). Workstream 8.2; the API and rules are in [podcasts.md](../api/podcasts.md). Standard and Full profiles (gated with the `library` routes; Control redirects to `/not-in-profile/podcasts`).

Open to guests like all playback; adding, checking and removing a podcast need the admin login (the page shows a "Sign in" link instead of the add form, and the Livewire actions `abort_unless(auth()->check(), 403)`, as the admin API needs the admin token).

## Layout (DESIGN.md)

Only existing components: `<x-page-header>` with a back button, `<x-alert>` for notices, `<x-empty-state>`, `<x-badge>`, `<x-artwork-thumb>` (the cover through the artwork proxy, a podcast icon on the hash gradient until processed), `<x-toggle>`, `<x-secondary-button>`, `<x-primary-button>`, `<x-text-input>`. Rows follow *Continue listening*.

- **Subscriptions**: a grid of rows (cover, title, author or episode count, an `N new` badge, an amber line when the last check failed). Clicking a row selects it (`?podcast=ID` in the URL, click again to deselect). Admin only, shown on hover/focus and always on touch: *Check for new episodes* and *Remove* (confirms). Below: the add form (RSS address, **Add podcast**).
- **Episodes**: under the subscriptions, "New episodes" (every unplayed episode of every podcast, newest first) or the selected podcast with its description, an *Only unplayed* switch and *Mark all played*. A row has the cover, the title (dimmed once played), the podcast name (new-episodes list only), date, length, "Stopped at 20:00" when there is a bookmark, a check (mark played/unplayed) and a play button (*Resume* when there is a bookmark). *Show more* adds 25.
- **Play** goes to the player pinned in this browser (`SelectedDevice`); without one the notice says "Pick a player in the bar below first." A device without `stream_url` shows the driver's message.
- **Continue listening** on `/library` lists episodes too (`ContinueListening::resume` plays an episode through the same `EpisodePlayback`).

The page does not poll: it re-renders after each action. Light and dark follow the standard tokens; no new colours, radii or icons beyond Font Awesome (`fa-podcast`, `fa-rotate`, `fa-check`, `fa-play`).

## Tests

`tests/Feature/Podcasts/PodcastLibraryTest.php` (page, selection, played, admin-only actions, play, continue listening, MCP), `PodcastsApiTest.php` (API, refresh, retention, resume) and `tests/Unit/PodcastFeedParserTest.php`. Feeds are `Http::fake` fixtures (`tests/Support/MakesPodcastFeeds.php`); no network.
