# Shared Blade components

The building blocks of `DESIGN.md` (`resources/views/components/`). Use them instead of pasting class strings; the render test is `tests/Feature/UiComponentsTest.php`.

| Component | Props / slots | Use for |
|---|---|---|
| `<x-card>` | `compact` (rounded-2xl), `flush` (clip children), `href` (link card); caller adds padding | Every surface. One radius, border, shadow |
| `<x-page-header>` | `title`, `subtitle`, `back` (url); slot `actions`; default slot under the subtitle | Inside `<x-slot name="header">` of every page |
| `<x-alert>` | `type` success\|error\|warning\|info, `icon`; slot `action` | Flash messages (the layout uses it) and inline notices |
| `<x-badge>` | `variant` neutral\|outline\|success\|warning\|danger\|info | Genre/format/status pills |
| `<x-empty-state>` | `icon`, `title`, `text`, `bare`; default slot = call to action | Lists with nothing in them |
| `<x-table>` + `<x-table.th hide="sm\|md">` | slot `head` (the `<th>` cells), default slot = `<tr>` rows | Data tables (scrolls sideways inside its card) |

Navigation: `<x-nav-list>` (the one list behind sidebar and drawer), `<x-nav-item>`, `<x-nav-mobile>` (top bar + drawer), `<x-wordmark>`. Existing: `primary|secondary|danger-button`, `text-input`, `select`, `toggle`, `back-button`, `modal`, `settings-group`/`settings-row`, `album-grid`.

Tailwind only generates classes it sees as whole strings, so variants are literal maps inside each component. Never build a class with string interpolation (`bg-{{ $color }}-50`).
