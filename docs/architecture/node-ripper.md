# Ripping a CD on a node (workstream 18.1, N2)

Status (2026-11-21): **built against fakes, unverified on hardware.** A ReMoment Node with the `ripper` role reads the disc in its own drive, the server offers the MusicBrainz releases of that disc, the admin confirms one, and the node rips it with cyanrip and uploads tagged FLAC files into the library. Nothing here has run with a real drive, a real cyanrip or a real node. Roadmap: [v1-roadmap.md](v1-roadmap.md) sections 7 and 18. Plan and security rules: [node-agent.md](node-agent.md).

The desktop **ReMoment Ripper** app (`ripper/`) stays: it is for a person at a Mac (and later Windows and Linux) desktop and uploads through the admin upload. The node role is the headless, server-driven variant for a Pi with a drive. Both end in the same ingest (`App\Domain\Storage\FileUploads`).

```
admin card / app / MCP ──▶ POST /api/admin/rips {node_id}              ripper.status ──▶ node reads the TOC, disc id
                              server: MusicBrainz discid (or TOC) lookup ◀── result { present, audio, disc_id, toc, tracks }
                          ──▶ GET  /api/admin/rips/{id}                 state choose_release, candidates[]
                          ──▶ POST /api/admin/rips {node_id, release_mbid}   ripper.rip { release_mbid, disc_id, meta }
                                                                        node: nice ionice cyanrip → temp dir (tags from meta)
                          ◀── progress every 2 s (POST /api/nodes/jobs/{id}/progress { progress })
                                                                        node ──▶ POST /api/nodes/files (Bearer node token), file by file
                              server: PhysicalRipLink, task tray, alert on failure ◀── result { tracks, uploaded, log, warnings }
```

## Flow

1. **Check the disc.** `POST /api/admin/rips { node_id }` queues a `ripper.status` job (an open check is returned again). The node reads the TOC (Linux CD-ROM ioctls), computes the **MusicBrainz disc id** (SHA-1 of the TOC, base64 with `._-`) and answers `{ present, audio, disc_id, toc, tracks }`. No disc: `present: false`. A data or mixed-mode disc: `audio: false`, and a rip of it is refused.
2. **Candidates.** When the check's result arrives (`NodeController::result` → `Rips::afterFinish`), the queued job `LookupRipCandidates` (behind the shared one-a-second `musicbrainz` limiter, retried like the other MusicBrainz jobs) asks MusicBrainz for the releases of the disc id, falls back to a **lookup by TOC** (`lookup: toc`: a different pressing of the same album) and stores `candidates` on the check's result. A candidate is a release whose medium carries the disc id, or else has as many tracks as the disc; it brings artist, title, year, label, catalogue number, country, barcode and the track list (title, artist). A failed lookup is stored as `lookup_error`; the card still lets the admin paste a release id.
3. **Confirm one.** `POST /api/admin/rips { node_id, release_mbid }` needs a check that is at most `RIPPER_DISC_FRESH_SECONDS` (900) old with a disc in it. The release must be one of the candidates, or any MusicBrainz release with a medium of the same track count (looked up on the spot). The server queues `ripper.rip { release_mbid, disc_id, meta { album, artist, year, barcode, label, catalogue_number, tracks[] } }`. One rip per node at a time (`409 busy`); the same release again is the same rip (a double click).
4. **Rip.** The node checks that the disc in the drive still has the same disc id and track count (`disc_changed` otherwise, nothing is created), makes `<temp_dir>/rip-<job id>` (mode 0700) and runs `ionice -c 3 nice -n 15 cyanrip -d <device> -o flac -N [-s offset] [-r retries] -a <album tags> -t <n>=<track tags> …` in it. `-N` switches cyanrip's own MusicBrainz lookup off: **the server is the one place that knows the release**, the node writes what it was told: `MUSICBRAINZ_ALBUMID`, `BARCODE`, album, album artist, date, label, catalogue number and per track title and artist. Progress is parsed from cyanrip's output (a track number and a percentage, lenient) and sent every 2 s, which also renews the job's lease; a cancel from the admin (the answer of the progress call) kills cyanrip, and so does the server no longer knowing the job as ours (`409`).
5. **Check and upload.** All FLAC files must be there (`incomplete_rip: found X of N tracks` otherwise). The node reads each file's Vorbis comments and warns (not fails) when `MUSICBRAINZ_ALBUMID` is missing, since cyanrip's metadata flags are unverified. Each file goes to `POST /api/nodes/files` with the node's own Bearer token. A marker file in the rip's directory remembers which files the server confirmed (a retry of the phase sends only the rest); a failed file is retried five times with growing waits (network errors, 5xx, 408, 429; a 403/422 is final); identical bytes already in the library are kept once on the server, so a repeat is harmless. The temp directory is removed in every outcome, and `cleanStale` removes `rip-<job id>` directories an interrupted run left (only those names, only directly inside the temp directory).
6. **Done.** The node's result is `{ tracks, uploaded: [{name, file_id, track_id}], log, warnings, disc_id, release_mbid }` (the log is cyanrip's log file, capped at 32 KiB, kept on the job). The server then ties the uploaded files to the CD on the shelf: the tags already did it at indexing (`PhysicalRipLink`, see [physical.md](../api/physical.md)), and `Rips::afterFinish` repeats it from the job's release id and barcode, so a ripper that wrote no tags still links. The item is `ripped` and linked to the album. Files that the server knows came from this job are the ones in `files` of the rip.

## Server side

| What | Where |
|------|-------|
| Domain | `App\Domain\Ripper\Rips` (nodes, `start`, `payload`, candidates lookup, alerts, tray tasks), `DiscLookup` (MusicBrainz discid/TOC/release, `normalize`), `RipException` |
| No table | a rip is two kinds of `node_jobs` row: `ripper.status` (the check, its result holds the disc and the candidates) and `ripper.rip`; the id of a rip is its job id. Progress is the job's last report in the cache (`NodeJobs::progressOf`, kept a day), the uploaded file ids are in the cache (`rip:files:{job}`); the outcome is in the job's `result`. **No migration** |
| Job types | `ripper.status`, `ripper.rip` in `NodeJobs::TYPES` (role `ripper`; the check expires after 60 s unanswered, a rip after 1 h; params validated, only typed values) |
| API | `GET /api/nodes/rippers` (open), `POST|GET /api/admin/rips`, `GET /api/admin/rips/{id}`, `POST /api/admin/rips/{id}/cancel` (admin token), `POST /api/nodes/files` (node token); see [rips.md](../api/rips.md); MCP tool `rips` |
| Upload | `App\Domain\Storage\FileUploads` is the one ingest of the admin upload (`POST /api/admin/files`) and the node upload; `Api\NodeFileController` lets a node upload only while it has an accepted or running, not cancelled `ripper.rip` job (`403 no_rip_job`), and never reaches an admin route |
| Web | the **Rip this disc** card (`Livewire\RipDisc`) at the top of `/library/collection`: per ripper node a "Check the disc" button, the candidates with "Rip this", a field for a MusicBrainz release id, a progress bar and Cancel while it runs, the outcome after; starting and cancelling need the admin login. Hidden without a ripper node or outside the profile |
| Task tray | a rip shows as "Ripping artist – album" with a measured percent (the ripping phase is the first 90 %, the upload the rest): `Rips::tasks()` read by `UserTasks::active()` |
| Alert | `rip.failed` (warning, key = the job id): a rip that failed, was lost (the node stopped reporting; lease ran out) or expired (never picked up) in the last `RIPPER_ALERT_HOURS` (24); raised at once when the node reports a failure, by `alerts:check` for lost and expired; resolved when a later rip on that node succeeds or the hours pass; a cancelled rip raises nothing; see [alerts.md](alerts.md) |
| Profile | feature `rip_upload` (Full), like the admin upload of the Ripper app: the routes answer `422 not_available_in_profile`, the card is hidden |
| Config | `config/ripper.php` (`RIPPER_DISC_FRESH_SECONDS`, `RIPPER_ALERT_HOURS`) |

## Node side (`node/`, Go)

| What | Where |
|------|-------|
| Role, config, handlers | `node/ripper.go` (`RipperConfig`, `ripArgs`, `rip`, `upload`, `parseRipLine`), jobs registered in `node/jobs.go`, the role state in the heartbeat (`roles.ripper`: `idle`, `busy`, `error`, `absent` without a drive) and `capabilities.ripper { drive, engine }` |
| TOC and disc id | `node/ripper_toc.go` (`TOC`, `discID`, `tocString`), the drive read in `node/ripper_toc_linux.go` (`CDROMREADTOCHDR`/`CDROMREADTOCENTRY`, `O_NONBLOCK`), a stub that says "Linux only" elsewhere |
| FLAC tags | `node/ripper_flac.go` reads the Vorbis comments (metadata blocks only) |
| Config | `ripper` in the node config: `device` (`/dev/sr0`), `cyanrip`, `temp_dir`, `nice`, `no_ionice`, `offset`, `retries`; `REMOMENT_NODE_RIPPER_DEVICE` |
| Tests | `node/ripper_test.go`: the disc id against vectors from an independent implementation, `ripArgs` (array, escaping, refusal of a free device), param validation, flow through a **fake cyanrip** (the test binary behind a one-line script: ok, fail, incomplete, untagged, slow) with a fake drive and a fake server: upload order and token, retry of one file, a refused upload not retried, cancel kills the ripper, temp directory always clean, disc changed / no disc / data disc refused, one rip at a time, `cleanStale` touching only `rip-<ULID>` |

## Security

- **The drive, the program and the directories are the node's own config**, never a job param. A job carries the release id, the disc id and the tags; the node validates every field again (UUID, disc id pattern, no control characters, lengths, track numbers 1..N in order) and passes them as **arguments** of an argument array (no shell), escaped for cyanrip's `key=value:key=value` strings. Tags become file content and cyanrip's file names, never a path or a command.
- **The node never holds an admin token.** It uploads with its own Bearer token to a node route that works only during its own running rip. A node token reaches no admin route.
- **Write-only.** The node does not eject: no privileged helper (decided 2026-11-20). The UI tells the person to take the disc out. `ripper.eject` is not built.
- `ionice -c 3` and `nice` keep the rip behind the node's other work; one rip at a time per node; the systemd drop-in gives the node what cyanrip needs (`dist/remoment-node.service.d/ripper.conf`: devices visible, `cdrom` group, 384 MB, low CPU and I/O weight).

## Unverified (all hardware)

- **cyanrip itself**: that it builds and runs on a Pi 2/3 (armv7), that the flags in `ripArgs` are right (`-d -o -N -s -r -a -t`; written from its documented options, the first thing to check with `cyanrip -h`), that arbitrary album keys (`MUSICBRAINZ_ALBUMID`, `BARCODE`) reach the FLAC tags (the node warns when they do not, and the server links from the job anyway), the shape of its output lines for the progress parser (lenient on purpose; a rip with unparsed output still ends), that it writes its log next to the files, and how long a disc takes beside analysis.
- **The TOC ioctls** (`CDROMREADTOCHDR`, `CDROMREADTOCENTRY` in LBA format, the lead-out entry `0xAA`, the data-track bit) on a real drive and kernel, and the disc id against a real disc that MusicBrainz knows (only checked against an independent implementation of the specification).
- A **drive that spins down or reports `ENOMEDIUM` late**; a mixed-mode or copy-protected disc; multi-disc releases (the medium is chosen by the disc id; the disc number is not tagged yet).
- The systemd drop-in on Raspberry Pi OS; the 2 s progress ticks and the lease (60 s) on a slow secure rip of a damaged track; an upload of 30 to 40 MB per track on a slow Wi-Fi link.
- The card in a browser with a real rip.

## Not built

- `ripper.eject` (decided: the node is write-only), the retained MQTT topic `remoment/node/{id}/disc` (the disc is read on demand by `ripper.status`; the drive is never polled, so it never spins up by itself), AccurateRip confidence from the log as a field (the raw log is kept on the job; the library health report does not read it yet), flagging an unconfident rip, a resumable upload inside one file (resume is per file), rips from the apps' UI (the API and MCP exist), disc number tags for multi-disc releases, a Windows or macOS node.
