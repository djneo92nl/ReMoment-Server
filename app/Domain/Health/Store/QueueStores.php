<?php

namespace App\Domain\Health\Store;

use Illuminate\Support\Facades\Queue;

/** The store of the configured queue driver: Redis (production) or database; null for sync, SQS and the rest. */
final class QueueStores
{
    private static ?QueueStore $override = null;

    public static function current(): ?QueueStore
    {
        return self::$override ?? match (config('queue.connections.'.config('queue.default').'.driver')) {
            'redis' => new RedisQueueStore(fn () => Queue::connection()->getConnection()),
            'database' => new DatabaseQueueStore,
            default => null,
        };
    }

    /** Replaces the store (a test's in-memory one); null restores the driver's. */
    public static function using(?QueueStore $store): void
    {
        self::$override = $store;
    }
}
