<?php

namespace App\Domain\Storage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The files dropped in the browser on /settings/storage and where each one is in the pipeline
 * (uploading → parsing → copying → enriching → done, or failed). Kept in the cache for the page's table:
 * it is progress to look at, not data to keep. See ProcessLibraryUpload.
 */
class LibraryUploads
{
    public const UPLOADING = 'uploading';

    public const PARSING = 'parsing';

    public const COPYING = 'copying';

    public const ENRICHING = 'enriching';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const ACTIVE = [self::UPLOADING, self::PARSING, self::COPYING, self::ENRICHING];

    private const KEY = 'library-uploads';

    private const KEEP = 100;

    /** A row still active after this long is treated as lost (browser closed, no worker). */
    private const STALE_AFTER_MINUTES = 120;

    /** @return array<string, mixed> the new row */
    public static function start(string $name, int $size): array
    {
        $row = ['id' => (string) Str::ulid(), 'name' => $name, 'size' => $size, 'state' => self::UPLOADING, 'message' => null];

        self::locked(function (array $rows) use ($row) {
            array_unshift($rows, $row + ['updated_at' => now()->toIso8601String()]);

            return array_slice($rows, 0, self::KEEP);
        });

        return $row;
    }

    /** @param  array<string, mixed>  $fields */
    public static function update(string $id, array $fields): void
    {
        self::locked(fn (array $rows) => array_map(
            fn (array $row) => $row['id'] === $id ? array_merge($row, $fields, ['updated_at' => now()->toIso8601String()]) : $row,
            $rows,
        ));
    }

    public static function fail(string $id, string $message): void
    {
        $failedAt = self::find($id)['state'] ?? null;

        self::update($id, ['state' => self::FAILED, 'message' => $message, 'failed_at' => $failedAt]);
    }

    /** @return list<array<string, mixed>> newest first, lost rows shown as failed */
    public static function all(): array
    {
        return array_map(function (array $row) {
            if (in_array($row['state'], self::ACTIVE, true) && now()->parse($row['updated_at'])->lt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
                $row['state'] = self::FAILED;
                $row['message'] = 'Lost: no progress for two hours (is the queue worker running?).';
            }

            return $row;
        }, Cache::get(self::KEY, []));
    }

    public static function find(string $id): ?array
    {
        return collect(self::all())->firstWhere('id', $id);
    }

    public static function active(): bool
    {
        return collect(self::all())->contains(fn ($row) => in_array($row['state'], self::ACTIVE, true));
    }

    /** Drops the rows that are finished (done or failed). */
    public static function clearFinished(): void
    {
        self::locked(fn (array $rows) => array_values(array_filter($rows, fn ($row) => in_array($row['state'], self::ACTIVE, true))));
    }

    /** @param  callable(array): array  $change */
    private static function locked(callable $change): void
    {
        Cache::lock('library-uploads-lock', 10)->block(5, function () use ($change) {
            Cache::put(self::KEY, $change(Cache::get(self::KEY, [])), now()->addDay());
        });
    }
}
