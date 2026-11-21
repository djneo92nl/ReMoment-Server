<?php

namespace App\Domain\Tasks;

use App\Domain\Export\ExportSelection;
use App\Models\Export;
use Illuminate\Support\Facades\Cache;

/**
 * What a user started and is still running, for the floating task tray (`Livewire\TaskTray`): exports (read from
 * their rows) and CD rips on a node (read from their node jobs), and cache-held tasks for deep analysis (stems, sections) and the analysis backlog. Later kinds (sync to
 * a device) put a task the same way. A task is `queued` (waiting for a worker) or `running`, with a `percent` when
 * known: measured (`done` of `total`), or estimated from the expected duration (`estimated: true`, never past 95), or
 * null (indeterminate). A finished task is forgotten, so it disappears. Cache entries carry a TTL so a worker that
 * dies never leaves one behind. No accounts: the tray shows what is going on household-wide.
 */
final class UserTasks
{
    private const INDEX = 'usertasks:index';

    /** The longest a task may stay in the tray without an update. */
    private const TTL = 43200;

    /** Starts (or restarts) a task. $estimateSeconds gives a time-based percent while running. */
    public static function put(string $key, string $kind, string $title, string $state = 'queued', ?int $total = null, ?int $estimateSeconds = null): void
    {
        self::save($key, [
            'key' => $key, 'kind' => $kind, 'title' => $title, 'state' => $state, 'done' => 0, 'total' => $total,
            'estimate' => $estimateSeconds, 'started_at' => $state === 'running' ? now()->timestamp : null,
        ]);

        Cache::put(self::INDEX, array_values(array_unique([...Cache::get(self::INDEX, []), $key])), self::TTL);
    }

    /** Marks a queued task as running now. */
    public static function running(string $key): void
    {
        if ($task = Cache::get(self::entry($key))) {
            self::save($key, ['state' => 'running', 'started_at' => now()->timestamp] + $task);
        }
    }

    /** Counts one unit done; the task ends by itself at its total. No-op for an unknown task. */
    public static function advance(string $key, int $by = 1): void
    {
        $task = Cache::get(self::entry($key));

        if (!$task) {
            return;
        }

        $task['done'] += $by;
        $task['state'] = 'running';
        $task['started_at'] ??= now()->timestamp;

        if ($task['total'] !== null && $task['done'] >= $task['total']) {
            self::done($key);

            return;
        }

        self::save($key, $task);
    }

    /** Sets the progress of a task to an absolute value (a sync reports files done of total); the task ends at its total. */
    public static function set(string $key, int $done, int $total): void
    {
        $task = Cache::get(self::entry($key));

        if (!$task) {
            return;
        }

        $task['done'] = $done;
        $task['total'] = $total;
        $task['state'] = 'running';
        $task['started_at'] ??= now()->timestamp;

        if ($total > 0 && $done >= $total) {
            self::done($key);

            return;
        }

        self::save($key, $task);
    }

    public static function done(string $key): void
    {
        Cache::forget(self::entry($key));
        Cache::put(self::INDEX, array_values(array_diff(Cache::get(self::INDEX, []), [$key])), self::TTL);
    }

    /** @return list<array{key: string, kind: string, title: string, state: string, percent: ?int, estimated: bool, detail: ?string}> */
    public static function active(): array
    {
        return [...self::exports(), ...self::cached(), ...\App\Domain\Ripper\Rips::tasks()];
    }

    /** @return list<array<string, mixed>> */
    private static function exports(): array
    {
        return Export::query()->whereIn('status', [Export::QUEUED, Export::RUNNING])->oldest('id')->limit(10)->get()
            ->map(fn (Export $e) => [
                'key' => "export:{$e->public_id}", 'kind' => 'export', 'title' => 'Export: '.ExportSelection::title($e),
                'state' => $e->status,
                'percent' => $e->status === Export::RUNNING && $e->files_total > 0 ? (int) floor($e->files_done / $e->files_total * 100) : null,
                'estimated' => false,
                'detail' => $e->status === Export::RUNNING && $e->files_total > 0 ? "{$e->files_done} of {$e->files_total} files" : null,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private static function cached(): array
    {
        $tasks = [];
        $index = Cache::get(self::INDEX, []);

        foreach ($index as $key) {
            if (!$t = Cache::get(self::entry($key))) {
                continue;
            }

            $percent = null;
            $estimated = false;
            $detail = null;

            if ($t['total']) {
                $percent = $t['state'] === 'running' ? min(99, (int) floor($t['done'] / $t['total'] * 100)) : null;
                $detail = $t['state'] === 'running' ? "{$t['done']} of {$t['total']}" : null;
            } elseif ($t['state'] === 'running' && $t['estimate'] && $t['started_at']) {
                $percent = min(95, (int) floor((now()->timestamp - $t['started_at']) / $t['estimate'] * 100));
                $estimated = true;
            }

            $tasks[] = ['key' => $t['key'], 'kind' => $t['kind'], 'title' => $t['title'], 'state' => $t['state'], 'percent' => $percent, 'estimated' => $estimated, 'detail' => $detail];
        }

        // Drop index rows whose entry expired.
        $live = array_column($tasks, 'key');
        if (count($live) !== count($index)) {
            Cache::put(self::INDEX, $live, self::TTL);
        }

        return $tasks;
    }

    private static function entry(string $key): string
    {
        return "usertasks:{$key}";
    }

    /** @param  array<string, mixed>  $task */
    private static function save(string $key, array $task): void
    {
        Cache::put(self::entry($key), $task, self::TTL);
    }
}
