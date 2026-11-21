<?php

namespace App\Domain\Health\Store;

use Illuminate\Support\Carbon;

/**
 * The driver-specific reads and edits queue management needs on one queue (docs/architecture/queue-management.md):
 * counts, the waiting and delayed jobs, removal of one of them and, where the driver is a list, moving jobs to the head.
 * Jobs a worker has reserved (running) are never listed or edited: a running job finishes.
 */
interface QueueStore
{
    /** @return array{waiting: int, delayed: int, running: int, oldest_waiting: ?Carbon, longest_running: ?int} */
    public function stats(string $queue): array;

    /**
     * Waiting and delayed jobs, head of the queue first, at most `$limit`.
     *
     * @return list<array{ref: string|int, payload: string, state: 'waiting'|'delayed'}>
     */
    public function pending(string $queue, int $limit): array;

    /** Removes one listed job; false when it was taken by a worker (or removed) in the meantime. @param array{ref: string|int, payload: string, state: string} $entry */
    public function delete(string $queue, array $entry): bool;

    /** Whether waiting jobs can be moved to the head of the queue. */
    public function canReorder(): bool;

    /**
     * Moves the listed waiting jobs to the head, keeping their relative order; returns how many moved (a job a worker
     * took meanwhile is skipped).
     *
     * @param  list<array{ref: string|int, payload: string, state: string}>  $entries
     */
    public function toFront(string $queue, array $entries): int;
}
