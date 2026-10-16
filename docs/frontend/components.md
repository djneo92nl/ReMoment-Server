# Shared Blade components

The building blocks of `DESIGN.md` (`resources/views/components/`). Use them instead of pasting class strings; the render test is `tests/Feature/UiComponentsTest.php`.

| Component | Props / slots | Use for |
|---|---|---|
| `<x-card>` | `compact` (rounded-2xl), `flush` (clip children), `href` (link card), `title` (heading inside), `tag` (root element, e.g. `section`); caller adds padding | Every surface. One radius, border, shadow |
| `<x-page-header>` | `title`, `subtitle`, `back` (url); slots `actions` (right, wraps on narrow screens), `badge` (next to the title), `meta` (subtitle line holding links); default slot under the subtitle | Inside `<x-slot name="header">` of every page |
| `<x-alert>` | `type` success\|error\|warning\|info, `icon`; slot `action` | Flash messages (the layout uses it) and inline notices |
| `<x-badge>` | `variant` neutral\|outline\|success\|warning\|danger\|info | Genre/format/status pills |
| `<x-empty-state>` | `icon`, `title`, `text`, `bare`; default slot = call to action | Lists with nothing in them |
| `<x-table>` + `<x-table.th hide="sm\|md\|lg">` | slot `head` (the `<th>` cells), default slot = `<tr>` rows | Data tables (scrolls sideways inside its card) |
| `<x-detail-row>` | `label`, `mono`; default slot = value | Label/value lines in a `<dl class="space-y-4 text-sm">` |
| `<x-text-input>` / `<x-select>` | `invalid` (red border), `size` md\|sm, `disabled` | Every text field and select; never a raw `<input>` |
| `<x-chip>` | link attributes; slot = label (+ muted count) | Pill links for browsing: genre, mood, tempo |
| `<x-section-heading>` | `title`, `href`, `link` | Uppercase label above a block, with an optional "more →" link |
| `<x-fav-badge>` | none; put it in a `relative` box | The favourite star in a cover's corner |
| `<x-shelf>` | `title`, `href`; slot = fixed-width cards | Sideways row with a bold heading and `›` link (library hub) |
| `<x-media-card>` | `href`, `src`, `title`, `subtitle`, `colors`, `play` (action template), `icon` | Playlist, radio and mixed-recent tiles, tinted by the cover |
| `<x-genre-tile>` | `genre`, `albums` | A genre tinted by the colours its covers share |
| `<x-quick-play>` | `action` (DeviceAction template) | Round play button on a cover: pinned player plays, else opens the picker |
| `<x-library-hero>` | `hero`, `scope` | Top of `/library`: name, counts, Shuffle, cover collage |

`<x-album-card>` has `tinted` (shelf card, quick play) and `quick`; `<x-artist-card>` has `round`. Tint colours come from `App\Domain\Artwork\ArtworkTint::style($colors)` (inline CSS variables, see DESIGN.md §10).

Navigation: `<x-nav-list>` (the one list behind sidebar and drawer), `<x-nav-item>`, `<x-nav-mobile>` (top bar + drawer), `<x-wordmark>`. Existing: `primary|secondary|danger-button`, `text-input`, `select`, `toggle`, `back-button`, `modal`, `settings-group`/`settings-row`, `album-grid`.

**Livewire roots:** a component whose Blade view starts with `<x-card>` works (the card's root tag is dynamic on purpose; `tests/Feature/UiComponentsTest` guards it). Do not add an `@if`/`@foreach` before the root tag of a shared component: Livewire would put `wire:id` on the wrong element.

Tailwind only generates classes it sees as whole strings, so variants are literal maps inside each component. Never build a class with string interpolation (`bg-{{ $color }}-50`).
