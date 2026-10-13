# Sources: cross-platform fix plan

Guidelines: `sources.md`. Commit per repo; name the matching change in the other repos.

## Findings

- `GET /api/devices/{id}/sources` returns the raw ASE zone list: no `hidden`, no `sort_order`, no ordering (`DeviceController.php:221-229`). The web UI uses the stored `device_sources` table, which the API never exposes. No client can match it.
- Duplicates (Line-In, QPlay, Bluetooth, Music, B&O Radio twice) are the same input types borrowed from other products (`borrowed:true`, with `provider_*`). Borrowed starts `hidden = true` in `SourceSync.php:52`; clients cannot see that.
- iOS (`DeviceControls.swift:5-39`) shows a checkmark when `in_use`. ASE reports `in_use` true for almost everything (default `true`, `SourceControls.php:22`), so every row is checked. The iOS model lacks `borrowed`, `provider_name`, `source_type`, `hidden`.
- ESP32 details screen (`devices.cpp:185-212`) has the same checkmark bug and duplicates. The volume screen's SOURCE label takes the first `in_use` row, so it is likely wrong. Note: on the ESP, swipe right is volume; swipe left is Up Next.
- Web UI: the real manager is `DeviceSourceManager` (own vs borrowed, eye toggle, reorder). "Show less" is the standby quick-start in `device-card.blade.php`. The device-settings "Input" section is raw and also uses `in_use` as "active".
- There is no endpoint to hide, show or reorder sources.

## Phase 0: verify (blocker)

On a live B&O device, capture `BeoZone/Zone/Sources` and `ActiveSources`. Confirm what `inUse` means and find a reliable "active source" signal (`ActiveSources`, or `now_playing.source`). Decides the `active` field.

Decided: if no reliable signal exists, clients fall back to matching `now_playing.source` against the source type or name. No match means no active row.

## Phase 1: server (source of truth)

1. `openapi.yaml`: extend `Source` with `hidden`, `sort_order`, `active` and an optional `input` (physical connection such as "HDMI 5"; absent for digital sources). Coordinate with the TV device-controls agent so TV devices use the same field; add `visible` query on list; add `PUT /devices/{device}/sources/{source_id}` (admin bearer; body `hidden`, optional `sort_order`).
2. Expose `getActiveSources` and map `active`. Sonos: map `in_use` → `active`.
3. List endpoint reads from `device_sources` (via `SourceSync`), ordered by `sort_order`, with a default-hidden rule for QPlay and borrowed.
4. Update `server/CLAUDE.md` (Sources, Source Management) and tests (`DeviceApiTest`, `DeviceSourceManagerTest`).
5. Web UI: stop using `in_use` as "active" in device settings; use the new field and align the picker with the mockup (outline, dot, badge).

## Phase 2: iOS

1. `Models.swift`: add `sourceType`, `borrowed`, `providerName`, `hidden`, `sortOrder`, `active`; add decode tests.
2. Picker (`DeviceSheet`, `DeviceDetailPage`, `DeviceSourcesView`): visible-only, active row outlined, badges, no checkmarks; collapse + "Show all".
3. New "Manage sources" screen for admins (eye toggles, borrowed group), using the admin login already in the app.

## Phase 3: ESP32 and lib

1. `esp32-lib`: typed `Source` struct and parser; `activateSource`; a `setSourceHidden` call.
2. Client: move the picker to the volume screen (replace the read-only SOURCE label with a tappable row opening a list); fix `load_source` to use `active`.
3. Device settings page: "Sources" list with show/hide switches (new screen). Reference image: the TV device-controls mockup (rows with status dot, label and input badge); the settings variant swaps the tap-to-switch behaviour for an eye/switch toggle per row.
4. Remove the checkmark selection in the details screen.

## Phase 4: later clients

Android and web follow `sources.md` from the start.

## Order

0 → 1 → (2 and 3 in parallel) → 4. Phase 2 and 3 can ship a first cut without admin toggles: visible-only list, active row, badges.
