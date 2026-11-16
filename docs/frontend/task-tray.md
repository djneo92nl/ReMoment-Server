# Task tray

A floating tray (bottom right, above the player bar, `Livewire\TaskTray` in the app layout) shows what someone started and is still running, with progress, and drops it when it ends. Polls every 2 s while something runs, 10 s otherwise. Household-wide, no accounts.

Source: `App\Domain\Tasks\UserTasks::active()`.

- **Export**: read from `exports` rows (`queued`/`running`), measured percent = `files_done / files_total`.
- **Deep analysis** (stems, sections, stem audio): `DeepAnalysis::request()` puts a task, `AnalyzeTrackDeep` marks it running and removes it however it ends. Percent is *estimated* from `estimateSeconds` (shown `~`, capped at 95).
- **Analysis backlog** ("Run backlog" on /settings/analysis): task with `total` = queued files; each finished `AnalyzeAudioFile` job advances it, it ends at the total.
- **Sync to a device** (future): call `UserTasks::put/advance/done` with a `sync:` key.

Cache-held tasks have a 12 h TTL so a dead worker never leaves one behind. Not shown: the scheduled nightly analysis (not user-started).
