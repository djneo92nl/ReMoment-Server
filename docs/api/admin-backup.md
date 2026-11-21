# Backup copy (`/api/admin/backup/copy-path`)

The optional second copy of every backup, behind the admin token (see [admin-auth.md](admin-auth.md); `401 unauthenticated` without one). One directory path, no cloud and no credentials in the app (decided 2026-11-20): a mounted NAS share or a USB drive. What happens with it is in [backup-restore.md](../architecture/backup-restore.md#a-second-copy-backup_copy_path). The web card is on **Settings › Health › System & devices**; both use `App\Domain\Backup\BackupCopy`. MCP: tool `server_health`, actions `backup_copy`, `set_backup_copy_path`, `test_backup_copy`.

```
GET  /api/admin/backup/copy-path        → { path, keep, last_copy }
PUT  /api/admin/backup/copy-path        { path: "/mnt/nas/backups" | null }   → the same, saved
POST /api/admin/backup/copy-path/test   { path?: "/mnt/nas/backups" }          → { path, ok, message }
```

```json
{ "path": "/mnt/nas/remoment-backups", "keep": 7,
  "last_copy": { "ok": true, "message": "Copied to /mnt/nas/remoment-backups", "at": "2026-11-21T01:30:20+01:00", "file": "/mnt/nas/remoment-backups/remoment-backup-20261121-013000.zip" } }
```

- `path` is `null` when no copy is set; `last_copy` is `null` until the first copy after the path was set. `keep` is `BACKUP_KEEP`, applied to the copy too.
- **PUT** writes and removes a small file in the directory first. A relative path, one with `..`, the backup directory itself, a missing directory or one the server cannot write to is `422` (`errors.path`) and nothing is saved. The directory is **never created**: an unmounted share must show up as missing, not as a folder on the local disk. A `null` or empty `path` switches the copy off, forgets `last_copy` and resolves the alert.
- **POST test** changes nothing; without `path` it tests the saved one (`422` if there is none). `ok: false` is an answer (`200`), with the reason in `message`.
- A failing copy never fails the backup: it raises the `backup.copy_failed` alert (warning, see [alerts.md](../architecture/alerts.md)) and sets `last_copy.ok` to `false`; the next working copy resolves it.
