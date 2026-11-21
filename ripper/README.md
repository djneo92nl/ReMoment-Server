# ReMoment Ripper

Desktop app (Tauri v2) that rips an audio CD and adds it to the ReMoment library through the admin API
(`POST /api/admin/files`, bearer token from `POST /api/admin/login`; see `docs/api/admin-auth.md`).

Status: milestone 2 — sign in (token in the OS keychain) and upload one existing audio file. Ripping comes later.

## Develop

Needs Node and Rust (`brew install rustup && rustup-init`).

```bash
cd ripper
npm install
npm run tauri icon src-tauri/icons/icon.png   # once: generates the platform icons from the placeholder
npm run tauri dev
```

Where the sign-in is kept: release builds use the OS keychain. Debug builds (`tauri dev`) use `~/.remoment-ripper-dev/session.json` (readable by you only), because every rebuild is a new unsigned binary and macOS would ask for the keychain password each time. Sign out removes it.

`npm run build` type-checks and builds the web UI only (no Rust needed).

## The physical collection

The server ties a ripped disc to the CD on its shelf: the files are tagged with `MUSICBRAINZ_ALBUMID` (the release chosen in the app) and when they arrive through `POST /api/admin/files`, a CD of the physical collection (`/library/collection`) with that release id, or that barcode in a `BARCODE` tag, is marked **Ripped** and linked to the album. Nothing to do in the app, but pick the same MusicBrainz release as the one the item was added with (the barcode lookup on `/library/collection` stores it); a different pressing is not matched and can be marked ripped by hand. See `docs/api/physical.md`, "Ripped discs".
