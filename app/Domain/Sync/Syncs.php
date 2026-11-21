<?php

namespace App\Domain\Sync;

use App\Domain\Alerts\Alerts;
use App\Domain\Node\NodeJobs;
use App\Domain\Tasks\UserTasks;
use App\Models\Client;
use App\Models\NodeJob;
use App\Models\Setting;
use App\Models\SyncTarget;
use Illuminate\Support\Str;

/**
 * Runs syncs on nodes (docs/architecture/offline-sync.md section 6): creates the node jobs `sync.scan`, `sync.claim` and
 * `sync.apply` for a target, follows their progress into the task tray, stores the result on the target and raises an
 * alert when a run fails. One job per target at a time. REST is authoritative: the node reports over its job routes.
 */
final class Syncs
{
    /** The id of this installation, written into every claimed volume so two servers never fight over one stick. */
    public static function serverId(): string
    {
        $id = Setting::get('sync_server_id');
        if (!is_string($id) || $id === '') {
            $id = (string) Str::ulid();
            Setting::set('sync_server_id', $id);
        }

        return $id;
    }

    public static function taskKey(SyncTarget $target): string
    {
        return "sync:{$target->id}";
    }

    /** Sync now: `confirm` lets a plan with mass deletion go through (a person pressed the button). @throws SyncRefused */
    public static function start(SyncTarget $target, bool $confirm = false): NodeJob
    {
        $job = self::queue($target, 'sync.apply', ['target_id' => $target->id, 'confirm' => $confirm]);
        UserTasks::put(self::taskKey($target), 'sync', 'Sync to '.$target->name);

        return $job;
    }

    /** Read the volume of the node: marker, free space, what the manifest holds. No writes. @throws SyncRefused */
    public static function scan(SyncTarget $target): NodeJob
    {
        return self::queue($target, 'sync.scan', ['target_id' => $target->id]);
    }

    /** Write the marker on an unmarked volume ("Use this drive"). @throws SyncRefused */
    public static function claim(SyncTarget $target): NodeJob
    {
        return self::queue($target, 'sync.claim', ['target_id' => $target->id]);
    }

    /** Cancels the running or queued job of the target: the node finishes the current file, writes the manifest and stops. */
    public static function cancel(SyncTarget $target): bool
    {
        $job = $target->job();
        if (!$job || $job->isFinished()) {
            return false;
        }
        NodeJobs::cancel($job);
        if ($job->fresh()->isFinished()) {
            self::finished($job->fresh());
        }

        return true;
    }

    /** The latest job of a type for a target (scan results are read from here). */
    public static function lastJob(SyncTarget $target, string $type): ?NodeJob
    {
        return NodeJob::where('type', $type)->where('client_id', $target->node_id)
            ->where('params->target_id', $target->id)->orderByDesc('id')->first();
    }

    /** The node's progress report, into the task tray. */
    public static function progress(NodeJob $job, array $detail): void
    {
        if ($job->type !== 'sync.apply' || !($target = self::targetOf($job))) {
            return;
        }
        $key = self::taskKey($target);
        if (($detail['files_total'] ?? 0) > 0) {
            UserTasks::set($key, (int) ($detail['files_done'] ?? 0), (int) $detail['files_total']);
        } else {
            UserTasks::running($key);
        }
    }

    /** A sync job reached its final state (node result, cancel, or found lost/expired): store the outcome, free the target. */
    public static function finished(NodeJob $job): void
    {
        $target = self::targetOf($job);
        if (!$target) {
            return;
        }
        if ($target->active_job_id === $job->id) {
            $target->active_job_id = null;
        }

        if ($job->type === 'sync.apply') {
            UserTasks::done(self::taskKey($target));
            $result = (array) ($job->result ?? []);
            $target->last_run = [
                'job_id' => $job->id,
                'state' => $job->state,
                'result_state' => $result['state'] ?? null, // done | needs_confirmation
                'started_at' => $job->accepted_at?->toIso8601String(),
                'finished_at' => ($job->finished_at ?? now())->toIso8601String(),
                'summary' => array_intersect_key($result, array_flip(['applied', 'failed', 'released', 'removed', 'moved', 'bytes_written', 'warnings', 'failures'])),
                'error' => $job->error,
            ];
            if ($job->state === 'succeeded' && isset($result['owned_bytes'])) {
                $target->owned_bytes = (int) $result['owned_bytes'];
                $target->owned_count = (int) ($result['owned_count'] ?? 0);
            }

            if ($job->state === 'succeeded') {
                Alerts::resolve('sync.failed', $target->id);
            } elseif ($job->state !== 'cancelled') {
                Alerts::raise('sync.failed', $target->id, 'warning', "Sync to {$target->name} failed",
                    $job->error ?: "The sync job ended as {$job->state}.", data: ['target_id' => $target->id, 'job_id' => $job->id]);
            }
        }

        $target->save();
    }

    /** Ends targets whose job was lost, expired or cancelled without a result (alerts:check, every minute). */
    public static function reconcile(): void
    {
        NodeJobs::sweep();
        SyncTarget::whereNotNull('active_job_id')->get()->each(function (SyncTarget $target) {
            $job = $target->job();
            if (!$job) {
                $target->update(['active_job_id' => null]);
                UserTasks::done(self::taskKey($target));
            } elseif ($job->isFinished()) {
                self::finished($job);
            }
        });
    }

    /** The target a node may plan and report for: the one bound to it; another target is a 403, an unknown id a 404. */
    public static function nodeTarget(Client $node, string $targetId): SyncTarget
    {
        $target = SyncTarget::findOrFail($targetId);
        abort_unless($target->kind === SyncTarget::NODE && $target->node_id === $node->id, 403, 'This target is not bound to this node.');

        return $target;
    }

    private static function targetOf(NodeJob $job): ?SyncTarget
    {
        $id = $job->params['target_id'] ?? null;

        return is_string($id) ? SyncTarget::find($id) : null;
    }

    /** @param array<string, mixed> $params @throws SyncRefused */
    private static function queue(SyncTarget $target, string $type, array $params): NodeJob
    {
        if ($target->kind !== SyncTarget::NODE) {
            throw new SyncRefused('not_a_node_target', 'Only a node target is run by the server; an app target pulls by itself.', 422);
        }
        $node = $target->node;
        if (!$node || $node->status !== 'approved' || !in_array('sync-target', $node->activeRoles(), true)) {
            throw new SyncRefused('node_unavailable', 'The node is gone or has not got the sync-target role switched on.', 422);
        }
        if (($running = $target->job()) && !$running->isFinished()) {
            NodeJobs::sweep($node);
            if (!$target->job()?->isFinished()) {
                throw new SyncRefused('target_busy', 'A job for this target is already running.', 409);
            }
        }

        $job = NodeJobs::create($node, $type, $params);
        $target->update(['active_job_id' => $job->id]);

        return $job;
    }
}
