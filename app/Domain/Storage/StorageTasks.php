<?php

namespace App\Domain\Storage;

use App\Jobs\RunStorageTask;
use Illuminate\Support\Facades\Cache;

/**
 * Long file jobs (scan a location, import the inbox, reorganize the library) run on the queue; their state is
 * kept in the cache so the settings page can show it. One task of a kind (per location for scans) at a time.
 */
class StorageTasks
{
    public const SCAN = 'scan';

    public const IMPORT = 'import';

    public const REORGANIZE = 'reorganize';

    public const TASKS = [self::SCAN, self::IMPORT, self::REORGANIZE];

    /** A queued or running task older than this is treated as lost (no worker, crashed) and can be queued again. */
    private const STALE_AFTER_MINUTES = 120;

    public static function id(string $task, ?int $locationId = null): string
    {
        return $task.($locationId ? ':'.$locationId : '');
    }

    /** Queues the task; false when it is already queued or running. */
    public static function queue(string $task, ?int $locationId = null): bool
    {
        if (self::isActive($task, $locationId)) {
            return false;
        }

        self::put($task, $locationId, ['state' => 'queued']);
        RunStorageTask::dispatch($task, $locationId);

        return true;
    }

    /** @return array{state: string, message?: string, handled?: int, current?: string, updated_at: string}|null */
    public static function status(string $task, ?int $locationId = null): ?array
    {
        return Cache::get('storage-task:'.self::id($task, $locationId));
    }

    public static function isActive(string $task, ?int $locationId = null): bool
    {
        $status = self::status($task, $locationId);

        return $status !== null
            && in_array($status['state'], ['queued', 'running'], true)
            && now()->parse($status['updated_at'])->gt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public static function anyActive(array $locationIds): bool
    {
        foreach ([self::IMPORT, self::REORGANIZE] as $task) {
            if (self::isActive($task)) {
                return true;
            }
        }

        return collect($locationIds)->contains(fn ($id) => self::isActive(self::SCAN, $id));
    }

    public static function put(string $task, ?int $locationId, array $status): void
    {
        Cache::put('storage-task:'.self::id($task, $locationId), $status + ['updated_at' => now()->toIso8601String()], now()->addDay());
    }
}
