# Sources: UX guidelines

Applies to every client (web, iOS, ESP32, later Android and web app). Reference mockup: the TV device-controls source list (outlined active row, status dot, type badge, "Borrowed" badge).

## Concepts

A device has **sources** (inputs): its own, plus ones borrowed from other devices. Three separate questions, never mixed in one control:

| Question | Meaning | UI |
|---|---|---|
| Is it **active**? | The source playing right now | Outlined row, bold text. One at most. |
| Is it **available**? | Has signal / is usable | Status dot (green = signal, grey = idle) |
| Is it **visible**? | The household wants it in pickers | Eye toggle, in settings only |

`in_use` from the API is NOT "active" on B&O devices. Do not render it as a checkmark. Active comes from the server's `active` field; if the server has no reliable signal, fall back to matching `now_playing.source` against the source `source_type` or name. No match means no active row.

## Label and input

A source has a **label** (friendly name, always shown) and an optional **input** (the physical connection, e.g. HDMI 5, DVB, Line-In). TV devices have both; purely digital sources (Spotify, Music, B&O Radio) have no input.

- Input present → right-aligned badge with the input name.
- Input absent → no badge, no placeholder.
- Never invent an input from the label. The badge is a connection, not a category (the `Borrowed` badge is separate).

## Rules

1. **Picker and settings are different screens.**
   - Picker (now playing, quick settings, device sheet): shows visible sources only, tap switches. No toggles.
   - Settings (device settings, "Manage sources"): shows all sources, each with an eye toggle, reorder where the platform allows.
2. **No checkboxes or checkmarks as selection.** Checkboxes mean "can be turned on and off independently", which only fits visibility. Selection = outline + bold; one at a time.
3. **Real but unwanted sources are hidden, not removed.** QPlay is a real source, so it stays in settings and is hidden by default. Same for borrowed sources (hidden by default).
4. **Order is the server's `sort_order`.** Clients never re-sort or de-duplicate. Duplicates are a server/visibility problem, not a client one.
5. **Borrowed sources** carry a "Borrowed" badge and the label `Provider · Source`. In settings they sit in their own group below own sources.
6. **Type goes in a right-aligned badge** (DVB, HDMI 5, Radio). Icon on the left reflects `category`/`source_type`, not status.
7. **Streaming services** (Spotify, Deezer, Tidal, Qobuz) start through the library/play flow. They stay out of the quick-start source list, as in the web device card.
8. **Collapse long lists** to about 6 rows with "Show all (N)" / "Show less". Never wrap chips into six rows.
9. **Placement on ESP32**: picker lives on the volume screen (swipe right from Now Playing); show/hide lives on the device settings page. Do not put the picker in settings.
10. **Capability first**: hide the whole section without `source_control`; disable tapping without `source_activation`.
11. **Empty and error states**: no visible sources → "No sources shown" plus a link/hint to manage them. Failed switch → revert the highlight and show a brief error.
12. Visibility is household-wide and admin-controlled (shared admin login); non-admin clients show no eye toggles.
13. Black background, B&O styling, green/orange/red device states as in the root CLAUDE.md.

## Contract

`GET /devices/{id}/sources` must return `hidden`, `sort_order` and an `active` flag, already ordered; `?visible=1` filters. Visibility changes go through an admin endpoint. Update `openapi.yaml` first, then clients (see plan).
