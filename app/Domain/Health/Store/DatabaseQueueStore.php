<?php

namespace App\Domain\Health\Store;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The `jobs` table of the database driver (development, tests). Rows are taken in id order, so jobs cannot be moved to the front. */
final class DatabaseQueueStore implements QueueStore
{
    private function table(): \Illuminate\Database\Query\Builder
    {
        return DB::connection(config('queue.connections.database.connection'))->table(config('queue.connections.database.table', 'jobs'));
    }

    public function stats(string $queue): array
    {
        $now = now()->timestamp;
        $row = $this->table()->where('queue', $queue)->selectRaw(
            'sum(case when reserved_at is null and available_at <= ? then 1 else 0 end) as waiting, '
            .'sum(case when reserved_at is null and available_at > ? then 1 else 0 end) as delayed, '
            .'sum(case when reserved_at is not null then 1 else 0 end) as running, '
            .'min(case when reserved_at is null and available_at <= ? then created_at end) as oldest, '
            .'min(reserved_at) as reserved',
            [$now, $now, $now],
        )->first();

        return [
            'waiting' => (int) $row->waiting,
            'delayed' => (int) $row->delayed,
            'running' => (int) $row->running,
            'oldest_waiting' => $row->oldest ? Carbon::createFromTimestamp((int) $row->oldest) : null,
            'longest_running' => $row->reserved ? max(0, $now - (int) $row->reserved) : null,
        ];
    }

    public function pending(string $queue, int $limit): array
    {
        $now = now()->timestamp;

        return $this->table()->where('queue', $queue)->whereNull('reserved_at')->orderBy('id')->limit($limit)->get(['id', 'payload', 'available_at'])
            ->map(fn ($row) => ['ref' => (int) $row->id, 'payload' => $row->payload, 'state' => $row->available_at > $now ? 'delayed' : 'waiting'])
            ->all();
    }

    public function delete(string $queue, array $entry): bool
    {
        return $this->table()->where('id', $entry['ref'])->where('queue', $queue)->whereNull('reserved_at')->delete() > 0;
    }

    public function canReorder(): bool
    {
        return false;
    }

    public function toFront(string $queue, array $entries): int
    {
        return 0;
    }
}
