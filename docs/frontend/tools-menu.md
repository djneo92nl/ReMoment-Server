# Tools menu (`/tools`)

A **Tools** entry in the main menu for pages that *do* something with the library or the sound instead of browsing it, so more tools can be added without crowding the menu. Today there are two:

- **Mixer** (`/mixer`, Full profile): the [stem mixer](stem-mixer.md); a DJ mode ([dj-mode.md](dj-mode.md)) would grow inside it or beside it.
- **Export** (`/tools/export`, Standard and Full, feature `file_storage`): the music export. The page explains the steps (the Export button on an album, artist or playlist page starts one, see [../architecture/export.md](../architecture/export.md)) and shows the list of exports with progress, download, cancel and remove (`Livewire\ExportList`, the same component as Settings › Files › Exports, which stays). Exporting is admin only like before: a guest sees the explanation and a sign-in hint.

More tools (set builder, library cleanup, loudness matcher, announce, sleep and wake and others) are planned in [tools-ideas.md](tools-ideas.md).

**Sync to offline targets** ([../architecture/offline-sync.md](../architecture/offline-sync.md)) is a plan with only its diff built, so it has no entry yet; when it exists it is one more row (its Sync tab is planned under Settings › Files).

## How it works

- `App\Domain\Tools\Tools::all()` is the one list: per tool `key`, `label`, `icon` (a solid Font Awesome name), `route` (a route name), `routes` (route-name patterns that make its menu entry active), `feature` (a profile gate of `config/profile.php`) and a one-line `description`.
- `Tools::available()` keeps the tools whose feature the active profile has (`Profile::allows`). The same list feeds three places: the **Tools** entry in `components/nav-list.blade.php` (desktop sidebar and mobile drawer), its **sub-entries**, and the `/tools` page.
- The menu behaves like Library: Tools is a normal entry linking to `/tools`; the sub-entries (one per tool) show while you are on `/tools` or on a tool's page. With no available tool (Control has none today; Standard has Export) the entry is gone, and `/tools` answers 200 with "No tools in this profile".
- `/tools` (`ToolsController`, route `tools.index`) is open to guests like the tools themselves; it is not in a profile route row, because the hub is harmless everywhere. Each tool's own URL stays gated by its feature (`/mixer` is in the `deep_analysis` row of `config/profile.php`, `/tools/export` in `file_storage`).

## Adding a tool

1. Build the page and route; add its URL pattern to the right row of `config/profile.php` `routes` if it needs a feature.
2. Add one row to `Tools::all()` (use a `feature` that exists; a test checks the route name and the feature).
3. Nothing else: the menu, the sub-entry and the card on `/tools` follow. Document the tool in its own `docs/frontend/*.md` and link it from here.

Not tools: settings and admin pages (`/settings/*`, including the developer tools at `/settings/developer`, which stay admin-only).

## Tests

`tests/Feature/ToolsMenuTest.php`: the entry and the hub, the sub-menu opening in Tools only, Standard (Export only) and Control (none), the export page for a guest and the admin, and that every tool names a real route and profile feature. The menu is not checked in a browser.
