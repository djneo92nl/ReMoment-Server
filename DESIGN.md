# DESIGN.md — ReMoment web UI

Single source of truth for how the web UI looks. Read this before touching any Blade/Livewire view. Format after [awesome-design-md](https://github.com/voltagent/awesome-design-md); audit/pre-flight rules from [taste-skill](https://www.tasteskill.dev). It describes the system the app already converges on; when a page disagrees, the page is wrong.

## 1. Visual theme & atmosphere

A calm household control panel, not a streaming app. Quiet stone neutrals, generous space, large soft containers, one warm red accent reserved for the wordmark and destructive/live emphasis. Album and station artwork is content inside cards, never the chrome. No brand colour fills, no gradients on surfaces (apart from the body wash), no glow, no green-on-black.

Not Spotify, not a template: no hero banners, no centred marketing layouts, no decorative icons-in-circles. Every element earns its place by being something you can read or operate.

## 2. Colour roles

Neutral scale is **stone** in dark, **gray** in light. Do not mix `gray-*` into dark variants.

| Role | Light | Dark |
|---|---|---|
| Page | `gray-50`→`gray-100` wash | `stone-950`→`stone-900` wash |
| Surface (card) | `white` | `stone-900` |
| Surface raised / hover / active nav | `gray-100` | `stone-800` |
| Border | `gray-200/70` | `stone-800` |
| Text primary | `gray-900` | `gray-100` |
| Text body | `gray-900` | `gray-300` |
| Text muted | `gray-500` | `gray-400` |
| Text faint (labels) | `gray-400` | `gray-600` |
| Primary action | `gray-900` fill, white text | `stone-700` fill, white text |
| Accent | `red-600` (wordmark, destructive) | same |
| Success / error / info | `emerald` / `red` / `indigo`-`sky` 50/200/800 tints, `/20` fills in dark |
| Favourite badge | `fav` token (amber) | same |

Colours that appear in more than one view live in `tailwind.config.js` (`theme.extend.colors`), never as hex literals in views. Artwork-derived colours (`safe_colors`) are the only runtime colours.

## 3. Typography

System stack (`-apple-system … sans-serif`), no webfonts. Tight tracking on headings, `antialiased` body.

- Page title: `text-3xl md:text-4xl font-medium tracking-tight`; subtitle `mt-1.5 text-gray-500`
- Section label: `text-xs font-semibold uppercase tracking-wider` faint colour
- Body/controls: `text-sm`; secondary `text-xs`
- Numbers (volume, time, counts): `tabular-nums`
- Weights: 300 for the "Moment" wordmark only; 400 body; 500 headings/active; 600 only for small caps labels.

## 4. Component styles

Use the components; do not paste class strings.

- **Card** (`<x-card>`): `rounded-3xl`, surface colour, 1px border, `shadow-sm`. Compact variant `rounded-2xl`. Nested/inset blocks `rounded-xl`.
- **Settings group/row** (`<x-settings-group>`, `<x-settings-row>`): `rounded-2xl` divided list.
- **Buttons** (`<x-primary-button|secondary-button|danger-button>`, sizes sm/md/lg/xl): `rounded-xl` for sm/md, `rounded-2xl` for lg/xl. Never hand-roll `bg-gray-900` / `bg-indigo-600` buttons.
- **Inputs** (`<x-text-input>`, `<x-select>`, `<x-toggle>`): one height, `rounded-xl`, visible focus ring.
- **Page header** (`<x-page-header>`): title, subtitle, optional back button and actions.
- **Alert** (`<x-alert type>`): `rounded-2xl`, tinted fill, icon + text. Flash messages use it.
- **Badge** (`<x-badge>`): `rounded-full`, `text-xs`. **Empty state** (`<x-empty-state>`): centred, muted, one action.
- **Radius scale (only these):** `rounded-3xl` container · `rounded-2xl` group/alert/large button · `rounded-xl` control · `rounded-full` pill/avatar. No bare `rounded`, no `rounded-lg` on new work.
- Icons: Font Awesome solid, `w-5` in nav, aligned with text; never icon-only without `aria-label`.

## 5. Layout principles

- Left sidebar (`md+`) / top bar + drawer (below `md`); one shared nav list (`<x-nav-item>`), one active-route helper.
- Main padding `px-5 sm:px-8 lg:px-12`; content `max-w-*` chosen per page *type*: forms/settings `max-w-2xl`, lists/grids full width, detail pages `max-w-4xl`.
- Vertical rhythm: `gap-6` between blocks, `mb-10` after the page header.
- The sticky player bar is the bottom edge of the app. It is `sticky`, not `fixed`, so content ends above it; never add bottom padding to compensate.
- Grids are CSS grid with `gap-4`/`gap-6`; album/artist grids use `<x-album-grid>`.

## 6. Depth & elevation

Flat first. `shadow-sm` on cards, `shadow-xl` only on drawers/popovers/modals, `backdrop-blur` only on the two sticky bars. Separation comes from border + surface contrast, not shadow stacking.

**Stacking scale:** content 0 · mobile top bar 10 · sticky sub-navs (letter nav) 20 · drawer scrim 30 · drawer 35 · player bar 40 · modal/popover 50.

## 7. Do's and don'ts

Do: reuse components; keep light and dark in the same edit; use `text-sm` by default; give every interactive element a hover, focus-visible and disabled state; build class names whole (no `bg-{{ $color }}-50`), because Tailwind is compiled from the views and only sees complete strings.
Don't: add a hex colour to a view; use `gray-*` inside `dark:`; invent a radius, shadow or heading size; use `fixed` bottom elements besides modals; add decorative gradients, glow or emoji; ship a page half-migrated; add accounts, profiles or roles UI.

## 8. Responsive behaviour

Mobile first at 375px, no horizontal page scroll. Breakpoint `md` (768px) switches sidebar ↔ top bar. Touch targets ≥ 40px. On narrow screens the player bar keeps art, title, transport only. Dark mode follows `prefers-color-scheme`; both themes must hold contrast and hierarchy.

## 9. Agent prompt guide

> Read `DESIGN.md`. Build/modify the view using existing `x-*` components; stone dark / gray light; radius scale only; no hex literals; reuse `<x-page-header>`, `<x-card>`, `<x-alert>`. Before finishing, run the pre-flight: light+dark parity, contrast, focus/hover/disabled states, 375px no horizontal scroll, nothing hidden behind the sticky player bar, no placeholder or half-migrated markup.
