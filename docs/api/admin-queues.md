# Queues (`/api/admin/queues`)

Health of the background-job queues and the admin actions on them, for apps (admin bearer token, [admin-auth.md](admin-auth.md)); the web Queue tab of `/settings/health` and the MCP tool `queues` use the same code. Design, thresholds and limits: [queue-management.md](../architecture/queue-management.md). Contract: `docs/api/openapi.yaml` (`listQueues`, `pauseQueue`, `resumeQueue`, `cancelQueueJobs`, `prioritiseQueueJobs`).

## `GET /api/admin/queues`

```json
{
  "driver": "redis",
  "supported": true,
  "can_reorder": true,
  "data": [
    {
      "queue": "heavy", "state": "ok", "paused": false, "pausable": true,
      "waiting": 3, "delayed": 0, "running": 1,
      "oldest_waiting_at": "2026-11-21T10:02:11+00:00", "oldest_age_seconds": 412,
      "workers": 1, "worker": "present", "failed_24h": 0,
      "last_progress_at": "2026-11-21T10:08:50+00:00", "heartbeat_at": "2026-11-21T10:09:01+00:00",
      "lag_after_seconds": 15000, "lagging": false
    }
  ]
}
```

One row per queue the active profile runs (Control has `history` and no `storage`, `heavy`, `analysis` or `stems`). `state`: `ok`, `busy`, `stuck`, `paused`, `unknown`. `workers` is null and `worker` `unknown` when the worker processes can't be counted. `supported` is false and `data` empty for a queue driver that can't be listed (`sync`, SQS …). `can_reorder` is true for Redis, false for the database driver. A `stuck` queue, or a `lagging` one, has an active `queue.stuck` alert ([alerts.md](../architecture/alerts.md)).

## Actions

All need the admin token (`401` without). An unknown queue is `404 not_found`; a driver that can't be managed is `422 unsupported` for cancel and prioritise.

| Call | Body | Answer |
|---|---|---|
| `POST /api/admin/queues/{queue}/pause` | | `{ data: <queue row> }` with `paused: true`; `422 invalid` for `health` (the heartbeat's queue) |
| `POST /api/admin/queues/{queue}/resume` | | `{ data: <queue row> }` |
| `POST /api/admin/queues/{queue}/cancel` | `{ job? }` a class, short (`EnrichTrackLyrics`) or full | `{ cancelled, skipped_running }`: waiting and delayed jobs removed; heartbeat jobs are never removed |
| `POST /api/admin/queues/{queue}/prioritise` | `{ job }` | `{ moved }`: the waiting jobs of the class now at the head, in order; `422 unsupported` on the database driver |

Pause takes effect on the next poll of every worker, without a restart; **a job that is running finishes**. Cancel never touches a running job, is not undoable, and gives a cancelled unique job's lock back so the work can be queued again. See the design doc for what each does on each driver.
