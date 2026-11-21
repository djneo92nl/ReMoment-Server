<?php

namespace App\Queue;

use Illuminate\Queue\Connectors\RedisConnector;

class PausableRedisConnector extends RedisConnector
{
    public function connect(array $config)
    {
        return new PausableRedisQueue(
            $this->redis, $config['queue'],
            $config['connection'] ?? $this->connection,
            $config['retry_after'] ?? 60,
            $config['block_for'] ?? null,
            $config['after_commit'] ?? null,
            $config['migration_batch_size'] ?? -1,
        );
    }
}
