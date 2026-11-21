<?php

namespace App\Domain\Health\Store;

use Closure;
use Illuminate\Support\Carbon;

/** Redis queues: `queues:{name}` (list, waiting), `:delayed` and `:reserved` (sorted sets); the payload is the member. */
final class RedisQueueStore implements QueueStore
{
    /** @param Closure():object $connection the Redis connection of the queue */
    public function __construct(private readonly Closure $connection) {}

    public function stats(string $queue): array
    {
        $redis = ($this->connection)();
        $head = $redis->lindex("queues:{$queue}", 0);
        $pushed = $head ? (json_decode($head, true)['pushedAt'] ?? null) : null;

        $oldest = $redis->zrange("queues:{$queue}:reserved", 0, 0, ['withscores' => true]);
        $expires = $oldest ? (int) reset($oldest) : null;

        return [
            'waiting' => (int) $redis->llen("queues:{$queue}"),
            'delayed' => (int) $redis->zcard("queues:{$queue}:delayed"),
            'running' => (int) $redis->zcard("queues:{$queue}:reserved"),
            'oldest_waiting' => $pushed ? Carbon::createFromTimestamp((int) $pushed) : null,
            // A reserved job's score is its reservation time plus `retry_after`.
            'longest_running' => $expires === null ? null : max(0, now()->timestamp - ($expires - (int) config('queue.connections.redis.retry_after'))),
        ];
    }

    public function pending(string $queue, int $limit): array
    {
        $redis = ($this->connection)();
        $entries = [];

        foreach ($redis->lrange("queues:{$queue}", 0, $limit - 1) as $payload) {
            $entries[] = ['ref' => $payload, 'payload' => $payload, 'state' => 'waiting'];
        }
        foreach ($redis->zrange("queues:{$queue}:delayed", 0, max(0, $limit - count($entries) - 1)) as $payload) {
            $entries[] = ['ref' => $payload, 'payload' => $payload, 'state' => 'delayed'];
        }

        return $entries;
    }

    public function delete(string $queue, array $entry): bool
    {
        $redis = ($this->connection)();

        return $entry['state'] === 'delayed'
            ? (int) $redis->zrem("queues:{$queue}:delayed", $entry['payload']) > 0
            : (int) $redis->lrem("queues:{$queue}", 1, $entry['payload']) > 0;
    }

    public function canReorder(): bool
    {
        return true;
    }

    public function toFront(string $queue, array $entries): int
    {
        $redis = ($this->connection)();
        $moved = 0;

        // Workers take from the head (LPOP): push the last one first so the first ends up in front.
        foreach (array_reverse($entries) as $entry) {
            if ($entry['state'] === 'waiting' && (int) $redis->lrem("queues:{$queue}", 1, $entry['payload']) > 0) {
                $redis->lpush("queues:{$queue}", $entry['payload']);
                $moved++;
            }
        }

        return $moved;
    }
}
