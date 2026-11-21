<?php

namespace App\Domain\Health;

use App\Domain\Health\Store\QueueStore;
use App\Domain\Health\Store\QueueStores;
use App\Jobs\QueueHeartbeat;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * What an admin can do to the jobs of one queue (docs/architecture/queue-management.md): cancel the waiting and delayed
 * jobs of a class (or all of them), and run a class first. Jobs a worker has reserved are never touched. Heartbeat jobs
 * are never cancelled or moved (the stuck-queue check lives on them). A cancelled unique job gives its lock back, or
 * the same work could not be queued again until the lock expired.
 */
final class QueueControl
{
    public function __construct(private readonly ?QueueStore $store = null) {}

    private function store(): QueueStore
    {
        return $this->store ?? QueueStores::current() ?? throw new \RuntimeException('The queue driver cannot be managed.');
    }

    public function supported(): bool
    {
        return ($this->store ?? QueueStores::current()) !== null;
    }

    public function canReorder(): bool
    {
        return $this->supported() && $this->store()->canReorder();
    }

    /**
     * Removes the waiting and delayed jobs of a queue, optionally only those of one class (basename or full name).
     *
     * @return array{cancelled: int, skipped_running: int} `cancelled` jobs removed; `skipped_running` jobs a worker took while this ran
     */
    public function cancel(string $queue, ?string $job = null): array
    {
        $store = $this->store();
        $cancelled = $skipped = 0;

        foreach ($store->pending($queue, (int) config('queues.scan_limit')) as $entry) {
            if (!$this->matches($entry['payload'], $job)) {
                continue;
            }

            if ($store->delete($queue, $entry)) {
                $cancelled++;
                $this->releaseUniqueLock($entry['payload']);
            } else {
                $skipped++;
            }
        }

        return ['cancelled' => $cancelled, 'skipped_running' => $skipped];
    }

    /**
     * Moves the waiting jobs of a class to the head of their queue, in their order, so they run before the rest.
     *
     * @return int how many moved
     *
     * @throws \LogicException when the driver cannot reorder
     */
    public function prioritise(string $queue, string $job): int
    {
        $store = $this->store();
        if (!$store->canReorder()) {
            throw new \LogicException('This queue driver cannot reorder jobs; pause the other queues to run this one first.');
        }

        $entries = array_values(array_filter(
            $store->pending($queue, (int) config('queues.scan_limit')),
            fn (array $entry) => $entry['state'] === 'waiting' && $this->matches($entry['payload'], $job),
        ));

        return $entries === [] ? 0 : $store->toFront($queue, $entries);
    }

    /** Whether a payload is a job of `$class` (any class when null); heartbeat jobs never match. */
    private function matches(string $payload, ?string $class): bool
    {
        $name = json_decode($payload, true)['displayName'] ?? '';
        if ($name === QueueHeartbeat::class) {
            return false;
        }

        return $class === null || $name === $class || class_basename($name) === $class;
    }

    private function releaseUniqueLock(string $payload): void
    {
        try {
            $data = json_decode($payload, true)['data'] ?? [];
            $command = isset($data['command']) ? @unserialize($data['command']) : null;   // restores the job's models, which may be gone
            if (is_object($command) && ($command instanceof ShouldBeUnique || $command instanceof ShouldBeUniqueUntilProcessing)) {
                (new UniqueLock(app(Cache::class)))->release($command);
            }
        } catch (\Throwable) {
            // the lock then expires by itself (uniqueFor)
        }
    }
}
