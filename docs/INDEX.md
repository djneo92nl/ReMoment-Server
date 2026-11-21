# Documentation index

Moved out of CLAUDE.md to save context. Find the right doc here.

Detailed documentation lives in `docs/`. CLAUDE.md holds enough context to understand the project; the docs hold enough to implement or integrate without reading the source.

```
docs/
  user/                     End-user docs (English, tiered): install per method, first run, adding devices, troubleshooting, reverse proxy, upgrades and backups; start at README.md
  api/
    client-devices.md       Client device registration flow, all endpoints, firmware guide
    jellyfin.md             Jellyfin on a Philips TV: continue watching / next up and play in the Jellyfin app (GET /api/devices/{id}/jellyfin, POST .../jellyfin/play); wrappers over media-servers.md
    media-servers.md        Media servers (15, step 1): GET /api/media-servers, GET /api/devices/{id}/media (normalised items of every server that applies, merged), POST .../media/play {item, server?, position?}, `MediaItem`, capabilities, errors; MCP `media_servers`
    deep-analysis.md        Deep analysis of one track on request: POST/GET/DELETE /api/library/tracks/{id}/deep-analysis (open, no admin token), kinds `sections`, `stems` and `stem_audio`, states, where the result shows
    stems.md                Kept stems of a track for the apps' mixer (kind `stem_audio`, open): GET /api/library/tracks/{id}/stems (four signed MP3/FLAC URLs + bpm, key, Camelot), signed Range-capable download, storage `stems/{file id}`, cleanup (`stems:prune`), MCP `deep_analysis` action `stems`; the opt-in Demucs weights download (`GET /api/stems/weights` open, `POST /api/admin/stems/weights` admin, `weights`/`download_weights` MCP actions): nothing is downloaded by itself, the kinds wait for `ready`
    export.md               Export from the library as zip for an MP3 player/car radio (admin token; MP3, AAC m4a, Opus profiles, loudness option): estimate, start, poll, signed 15-minute download, cancel/remove; MCP tool `export`, `features.export`
    library.md              Library browse (artists, albums, playlists), favorites, play album/artist/playlist on a device (DLNA or Spotify), playlist artwork
    source-controls.md      Per-source controls (change disc, FM presets) from control profiles: GET/POST /api/devices/{id}/controls, resolution, transports
    physical.md             Physical collection (CDs, records, tapes): items by barcode or by hand, MusicBrainz barcode lookup, CSV import (Discogs export, preview/confirm), ripped discs linked by release id/barcode, `physical` availability provider, wishes closed by owning it
    scenes.md               Scenes (8.1): household-wide step lists (operationId + device + params, or delay) run through the REST API; GET/POST /api/scenes, activate, schedule, `last_run`, `features.scenes`; MCP tool `scenes`
    sync-groups.md          Sync groups (fake multiroom) REST API: leader + members, offsets, errors
    sub-devices.md          Sub devices (2a): `host` and `sub_devices` in the device detail, the `sub_devices` capability, admin link routes (`/api/admin/device-links`, `link-suggestions`), MCP `device_links`
    playback-move.md        Move playback to another device: POST /api/devices/{id}/move, what moves (library queue, radio, Spotify transfer), what is refused (local inputs)
    queue.md                Editing the queue: add, play next, remove, move, clear (queue_add/next/remove/move/clear), positions, `expect`, per-platform support
    server-info.md          GET /api/info bootstrap endpoint (API/MQTT/artwork addresses for clients)
    mcp.md                  MCP server at /mcp for AI agents: tools, admin-token gating, how tools reuse the REST API
    admin-auth.md           Token login of the single admin for apps: POST /api/admin/login, bearer tokens, revocation, what it gates
    stats.md                Listening statistics GET /api/library/stats (by hour/weekday/day/source/device, top lists), `plays` and `last_played_at` on details, what the numbers mean
    discover.md             Discover shelves GET /api/library/discover (`?sections=&limit=&seed=`): sections of items with a `reason` each (forgotten favorites, used to play, on this day, forgotten artists/songs, not played in a year from plays + favorites; new releases, mixes well with the last analysed play, listening modes, random picks); empty sections left out, thresholds in config/discover.php; MCP `browse_library` kind `discover`
    insights.md             Listening insights GET /api/library/insights (`?days=`, default 90, max 365): skip rate per artist (finished play under half the track), new vs repeat per week, mood/tempo by part of the day, late-night listening; Control 422, mood/tempo need Full, cached 5 min, timezone; MCP tool `listening_insights`
    history.md              Play history lists: GET /api/library/history, /devices/{id}/history, /albums/{id}/plays, /artists/{id}/plays; entry shape, cursor, filters (`source_type`, `match`), retention (`history:prune`), MCP tool `playback_history`
    overview.md             GET /api/library/overview: glance, decades, coverage, sound buckets, formats, analysis progress per stage
    library-health.md       GET /api/library/health/{report}: findings with ids, reason, duplicate copies and the physical-collection link; the `covers` report (no/tiny/non-square/differing embedded cover, also in Standard)
    sound-query.md          Sound filters on GET /api/library/tracks (bpm, camelot, arousal, mood, plr …), include=analysis, count_only, sort=plr|bpm|arousal, `filter` on play-listening-mode
    similar-tracks.md       Tracks that sound alike by the sound embedding: GET /api/library/tracks/{id}/similar (similarity, distance, reason, scope/device/cursor), POST /api/devices/{id}/library/play-similar (radio), `features.similar_tracks`, 422 not_analysed, MCP browse_library `similar`
    openapi.yaml            OpenAPI 3 contract for native clients (info, devices, playback, radio, multiroom, library, clients); update it when an endpoint's shape changes
    device-settings.md      Sound adjustment (bass/treble/loudness), Bluetooth pairing, device name: endpoints, shapes, per-platform support
  architecture/
    profiles.md             Profiles (workstream 1a): Control / Standard / Full, `PROFILE`, the gate table in config/profile.php, how scheduler, workers, routes, nav and containers are gated, SQLite for Control and its conditions, `profile:stress-sqlite`, `profile:upgrade`, what is unverified
    client-devices.md       DB schema, model, controller, admin UI internals
    line-in.md              Line-in as a stream source (18.2, N3): a node's ffmpeg pushes MP3 to Icecast `linein-{node}.mp3`, `GET /api/line-ins`, `POST /api/devices/{id}/line-in`, idle stop, 2 to 5 s latency, unverified on hardware
    node-agent.md           ReMoment Node (workstream 18, 1.1): Go agent plan, `kind: node` registration with roles, MQTT-as-doorbell command channel, job lifecycle, security, phases N0-N5; N0 and N1 built (`node/`)
    offline-sync.md         Sync to offline targets (workstream 18.3, plan; S0 `SyncDiff` built): targets (app, node volume, helper), rules (selections, export profile, budget, fill), key+fingerprint manifest on the target and stateless plan API, artifacts, node job types, iOS pull, deletion rules, phases S0-S6, owner questions
    backup-restore.md       backup:run / backup:restore: archive contents, secrets excluded by default, fresh-install restore, SQLite and SD-card note, upgrade flow
    schema-plan.md          Schema review before the migration squash (14.3): table inventory, findings, data vs schema migrations, squash design with the `schema:baseline` mechanism, pre-squash fix-list; rehearsal harness in scripts/schema/ (`schema.sh check` diffs the schema against committed MariaDB and SQLite baselines)
    availability-layer.md   Availability layer design (workstream 3): providers, catalogue_albums/album_availability, MusicBrainz discography sync, wishlist, API/UI, build order (plan, not built)
    diagnostics.md          Diagnostics bundle (9b): `diagnostics:bundle`, `GET /api/admin/diagnostics`, contents, the Redactor and what it removes, profiles, how to add a section
    alerts.md               Alert model (workstream 14.1): one place raises/resolves alerts, kinds and thresholds, dedupe, MQTT remoment/alerts/*, GET /api/alerts, ack, how to add a kind
    action-layer.md         Action layer spike (14.5): can an action be defined once and route/MCP/OpenAPI/scene/HA derived? numbers, recommendation (keep REST + openapi.yaml as the definition, scenes call operations by operationId), and what was built from it: operationIds, contract test, scene-operation list, `ha:package`
    credentials.md          Credentials layer (workstream 14.4): ServiceCredentials registry for Spotify/Last.fm/Discogs/AcoustID/TheAudioDB keys, /settings/services, encrypted secrets, backup exclusion, base-URL/relay hook, setup steps, how to add a service
    database.md             MariaDB (SQLite for tests only): why, .env settings, db:import-sqlite, recovering a corrupt file, driver-specific SQL
    manager.md              ReMoment Manager (workstream 10 skeleton, `manager/`): host service, Node one file, own token, 127.0.0.1:8099: status, logs, restart of one compose project, upgrade and rollback (`POST /upgrade`, `/rollback`: runs `app:upgrade --force --json` in the app container, previous image kept as `:previous`, rollback restores the failed upgrade's backup), audit, SMB serve (`manager/smb.js`: Samba fragment with fixed shares library/exports/inbox, one share user, dry run; unverified on a real Samba host), NAS mounts (`manager/mount.js`: an SMB share as systemd `.mount`+`.automount` units with a root-only credentials file, dry run with a plan token that a real call must repeat as `confirm`, `mount.unavailable` alert, Settings › Manager card, *Use as storage location*; unverified), installers (`manager/install/`: systemd unit + scripts for Linux, launchd agent for a Mac), CLI `remoment-manager`, security rules, tests with a fake docker; the Pi image still to do
    developer-tools.md      /settings/developer: quality test (transcode ladder played on a device), simulate now playing, MQTT console, device inspector, stream URL tester
    hardware-table.md       Generated supported-hardware table (9b): `hardware:table`, config/hardware-status.php statuses, the stale-page test
    device-drivers.md       Driver contracts, existing drivers, guide for adding a new brand
    media-servers.md        Media servers (workstream 15, step 1): contracts (`MediaServerDriverInterface` + capability interfaces), registry `config/media-servers.php`, table `media_servers`, normalised `MediaItem`, `DeviceMedia`, the single Jellyfin setting migrated into a row, link to devices (driver `canPlayOn` + device meta `media_servers`), how to add Plex/Emby/Kodi; Jellyfin only until after 1.0
    sub-devices.md          Sub devices (2a): `device_links`, one level deep, link kinds borrow/input/source, `LinkState`, routing generalised from SpotifyRouting, link actions, the follow rule, the Chromecast on the V1's HDMI 1; all hardware behaviour unverified
    beovision-tv.md         BeoVision / Beoplay V1 TV controls design: V1 API findings, one-way remote commands, TV screens (list, detail, remote), probe plan, phases; mostly unbuilt
    wiim.md                 WiiM (LinkPlay) driver on the vendor HTTP API (1.2) on top of UPnP: what is mapped, listener, discovery, quirks, wiim:probe; built from the document, unverified
    philips-tv.md           Philips Android TV on top of the UPnP driver (Jointspace v6): pairing command, standby, apps as sources, active app as now playing; what is unverified
    tv-devices.md           How a TV is modelled: sources/inputs, playback, power, RemoteControlInterface (remote keys); what the TV phase still has to do (Philips, BeoVision)
    upnp-renderer.md        Generic UPnP MediaRenderer fallback driver: server-managed queue, polling listener, discovery precedence, future research (native queues, radio, OpenHome)
    device-discovery.md     Discovery commands, listener startup, device_meta keys
    ase-api-map.md          What the B&O ASE REST API offers beyond the driver (Bluetooth, network, WiSA, sound), per model; the ase:map command
    sync-groups.md          Fake multiroom: a leader and members kept together by the server (state, track changes, drift seeks), what can be shared, precision (about ±2 s), where it runs
    masterlink-gateway.md   Master Link Gateway (MLGW/BLGW): IP protocol, bus log, the read-only mlgw:probe command; what is unverified
    proxmox-deployment.md   Ansible: LXC container on an existing Proxmox host, MiniDLNA server
    discovery-for-clients.md mDNS advertisement (_remoment._tcp via host Avahi) so clients find the server
    system-requirements.md  Hardware tiers for the full server (Pi minimum up to Essentia + ML), what runs, disk and network needs
    audio-analysis.md       Analysis of the own audio files: loudness, ReplayGain, Chromaprint/AcoustID, Essentia sidecar (BPM, key, mood), `analysis` queue, library:analyze, smart fields
    metadata-enrichment.md  Per-source enrichment jobs (MusicBrainz track/artist/album, Wikipedia, Cover Art Archive, lyrics, Spotify, Last.fm, TheAudioDB, Discogs), DLNA tags, canonical genres: markers, retries, rate limits, library:enrich
    artist-settings.md      Admin page per artist: photo/backdrop choice, MusicBrainz/Spotify/Last.fm mappings, details overrides, files, default source; the `manual` metadata source
    structure-service.md    Structure service (workstream 16.2): docker/structure, librosa novelty sections of one track on demand, contract, RAM/time measured, compose profile `structure`
    stems-service.md        Stems service (workstream 16.4): docker/stems, Demucs htdemucs in 60 s windows, per-stem loudness curves + vocal ratio + instrumental ranges of one track on demand, optionally the four stems kept as MP3/FLAC (`?audio=`, kind `stem_audio`), contract, own `stems` queue/worker, measured numbers, weights licence unclear so they are an opt-in download ("Weights": `weights.py`, pinned URL + SHA-256, `weights: missing` in `/health`, jobs refused until downloaded, card on `/settings/analysis`), compose profile `stems`
    track-stems-spike.md    Spike for 16.4: htdemucs vs htdemucs_ft vs Spleeter on nine tracks, RAM (windowing), licences of code and weights, the owner's verdicts, recommendation
    file-storage.md          Own music files: storage locations (local/S3), files + track_files tables, indexer, organizer, management, /stream, admin API
    radio.md                Radio: station-level now playing (providers: ICY, NTS), poll chain lifecycle, NTS import
    lastfm.md                Scrobbling, now-playing, auth flow, artist enrichment, backfill
    library-identity.md      One record per artist/album/track across sources: name keys, find-or-create, library:merge-duplicates
    live-updates.md          MQTT-over-WebSocket push to Livewire + /receiver, topics, fallback polling
    library-health.md        Reports on the own files: suspected transcodes, damaged, clipping, duplicates, lossy, incomplete albums, orphans
    listening-modes.md       Focus / Party / Sleep / Workout: preset smart-playlist definitions over the audio analysis, API and Library page row
    similar-tracks.md        Similar tracks / radio from the EffNet embeddings (built): `file_embeddings` (L2-normalised float32 BLOB, source of truth), the sidecar's `embedding`, stage version `2+ml`, exact PHP scan by default (measured: 0.55 s at 10k tracks, 5 s at 50k) and an optional sqlite-vec index (`SIMILAR_ENGINE`, `similar:index`, spike measurements), the Pi budget (estimate), what is unverified
    beolink-stream.md        B&O multiroom on any speaker (pseudo multiroom): beo listener → ffmpeg → Icecast, joins a B&O source, `stream_url` targets in the multiroom picker, opt-in compose profile
    home-assistant.md       Home Assistant (1.0, plan): MQTT discovery of stats/health/clients/sources, event triggers and a REST command package for automations built in HA; no media_player entities
    v1-roadmap.md            v1.0 scope: stability, TV, availability layer, discovery, Deezer/Tidal, physical library, CD import, extras, Home Assistant; order, dependencies, done-criteria, open questions (plan)
    streaming-services.md    Deezer and Tidal as per-device capabilities (ASE/Mozart play-by-id, Tidal Connect read-only): library entry, id mapping, contract, what to verify on hardware first (plan, not built)
    spotify-stream.md        Spotify on any speaker: librespot → ffmpeg → Icecast MP3 stream, `stream_url` capability, --onevent hook as now playing, opt-in compose profile
    smart-playlists.md       Rule-based playlists (source smart): rules JSON, SmartFields registry, builder, editor, daily refresh
    sd-card-export.md        Admin zip of recent covers/backgrounds + logos in the touch client's SD layout
    hardware-check.md       Pi 2 / Pi 3 hardware test: scripts/hardware-check/run.sh (host facts, idle memory, web timings, 24-hour SQLite stress, log scan; report.md/json, proposed budgets in budgets.conf), manual checklists, results tables; never run on a Pi
    refactor-hardware-check.md  Checklist for real devices after the duplicate-code cleanup: listeners, discovery, DLNA playback, volume, multiroom, API errors, artwork, MQTT
    plugin-architecture.md  Design doc: extracting drivers into composable packages
    setup-wizard.md          First-time setup wizard: steps, Setting flags, auto-redirect middleware
  frontend/
    receiver.md             /receiver view: JS globals, DOM structure, browser targets
    player-bar.md           Sticky bottom player: pinned device cookie, <x-play-button> default play target
    components.md           Shared Blade components (x-card, x-page-header, x-alert, x-badge, x-empty-state, x-table, x-shelf, nav ...) and their props; rules in DESIGN.md at the repo root
    stem-mixer.md           Stem mixer (`/mixer`): two decks of kept stems mixed in the browser with Web Audio (per-stem fader/mute/solo, tempo with SoundTouch pitch correction, key shift, Sync, crossfader, picker with mixes-with; beat grid, beat sync, quantize, cues and loops, stem transitions from the DJ plan D1-D4), memory, limits, tests
    discover.md             Discover hub (`/library/discover`, `Livewire\DiscoverHub`): every `Domain\Library\Discover` section drawn by its kind (shelves of media cards with the reason as a third line, song rows, listening-mode pills, Another pick), the marked `SLOT similar_tracks`, `x-shelf subtitle` and `x-media-card note`
    tools-menu.md           Tools menu (`/tools`): one list (`Domain\Tools\Tools`) feeds the menu entry, its sub-entries and the hub page; a tool is shown where its profile feature is on; how to add a tool
    library-picker.md       Reusable library picker (`Livewire\LibraryPicker`, `Domain\Library\LibraryPicks`): tabs artists/albums/tracks/playlists, drill-down, detail card, basket, `library-picked` event; used by the export page
    tools-ideas.md          More tools (plan, not built): set builder, library cleanup, loudness matcher, stem practice/export, tag fixer, import, wishlist, announce, sleep and wake, room matcher, party launcher, tag printer, year in review; hub groups, build order, open questions
    mixer-guide.md          Hands-on guide for the stem mixer: load, check the grid, match, cues, loops, transitions, troubleshooting, what to report
    dj-mode.md              Mini DJ mode (plan; D1 beat grid, D2 beat sync and quantize, D3 cues and loops, D4 stem transitions built in the mixer, D5+ not): beat grid, beat sync and quantize, cues and loops, stem transitions (bass swap), EQ and effects, keyboard/MIDI, record a set, auto-DJ; phases D1-D8, https needed for the key lock, open questions
    similar-tracks.md       `Livewire\TrackSimilar` ("Sounds like this" rows + Play similar radio on the pinned player): the album page's Track details row, how the Discover hub embeds it, `SimilarTracks::items()` for a hub section
    stem-lanes.md           Stem lanes (16.4): a lane per stem under the sections in the album page's Track details, own-scale curves, instrumental band, TrackStems states
    pwa.md                  Web app (8.6): manifest, root service worker (offline page and icons only, never API/Livewire/artwork), opt-in alert notifications from the MQTT alert topics, opt-in background Web Push (VAPID, `push_subscriptions`, `push:vapid`, `push:test`)
    task-tray.md            Floating tray of user-started tasks (export, stems/sections, analysis backlog; sync later): `UserTasks`, measured vs estimated percent, TTL
    library.md              /library hub: LibraryHome data, shelves, shuffle, favourites, tinted cards (ArtworkTint)
```

When adding a significant feature, add a matching doc in the relevant subfolder (`api/` for new REST endpoints, `architecture/` for server-side systems, `frontend/` for complex UI pages).
