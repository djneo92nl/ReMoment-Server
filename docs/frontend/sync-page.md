# Sync page (`/tools/sync`)

Under **Tools** (`Domain\Tools\Tools`, feature `file_storage`: Standard and Full), admin login only (a guest sees the sign-in hint, like the export page). One Livewire component, `Livewire\SyncTargets`, over the same `SyncService` and `Syncs` the REST API uses ([docs/api/sync.md](../api/sync.md), [offline-sync.md](../architecture/offline-sync.md)). It lists the sync targets **on nodes** (the app targets are made and used by the iOS app and are managed through the API).

Per target (a card):

- name, the node it is plugged into, profile, a one-line summary of the rules and budget, what the drive holds now (files and size from the last report);
- while a job runs: a badge ("Waiting for the node" / "Working"), a progress bar (files done of total, from the task tray task `sync:{id}`), **Cancel**;
- **Check drive** (`sync.scan`): filesystem, free of total, and whether the drive has no marker, is marked for this target, or belongs to another target or server. **Use this drive** (`sync.claim`) appears only for an unmarked drive; nothing is written before it is pressed, and nothing is ever formatted;
- **Estimate**: tracks selected, what would go on the drive, copied / converted / skipped, over-budget warning;
- **Sync now** (`sync.apply`) with the checkbox "allow removing many files": without it a plan that removes over 30 % of the files (or 500) applies nothing destructive and the card shows **Needs your yes**;
- the last result (state, when, added or replaced, removed, failed, warnings, the error);
- **Rules**: a simple form (quality, loudness, size budget in GB, how to fill, playlists in order, favourites, newest N, most played N). Album and artist selections made through the API are kept as they are when the form saves. Saving bumps the rules version when something changed.

At the bottom **Add a drive**: a name and a node that declares the `sync-target` role and has no target yet (one drive per node). Delete forgets the target; files on the drive stay.

The page polls every 3 s while a job runs, every 15 s otherwise, and reconciles runs whose job was lost. There is no eject: the node is write-only, so the page says to remove the stick when the job is done. Tests: `tests/Feature/SyncPageTest.php`.
