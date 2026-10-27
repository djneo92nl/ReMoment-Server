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
