<?php

namespace App\Domain\Health;

/**
 * Per-queue health and the admin actions on a queue as one set of answers, shared by the REST API
 * (`/api/admin/queues`), the web Queue tab and, through the API, MCP (docs/architecture/queue-management.md).
 */
class QueueAdmin
{
    public function __construct(private readonly QueueInspector $inspector = new QueueInspector, private readonly QueueControl $control = new QueueControl) {}

    public function supported(): bool
    {
        return $this->control->supported();
    }

    /**
     * @return array{driver: string, supported: bool, can_reorder: bool, data: list<array<string, mixed>>}
     */
    public function overview(): array
    {
        return [
            'driver' => (string) config('queue.connections.'.config('queue.default').'.driver'),
            'supported' => $this->supported(),
            'can_reorder' => $this->control->canReorder(),
            'data' => array_map(self::payload(...), $this->inspector->queues() ?? []),
        ];
    }

    /** @return array<string, mixed>|null the queue's row, null when it is not one of the app's queues */
    public function find(string $queue): ?array
    {
        foreach ($this->inspector->queues() ?? [] as $row) {
            if ($row['queue'] === $queue) {
                return self::payload($row);
            }
        }

        return null;
    }

    public function known(string $queue): bool
    {
        return in_array($queue, Queues::all(), true);
    }

    /** @throws \InvalidArgumentException when the queue can't be paused */
    public function pause(string $queue): void
    {
        QueuePause::pause($queue);
    }

    public function resume(string $queue): void
    {
        QueuePause::resume($queue);
    }

    /** @return array{cancelled: int, skipped_running: int} */
    public function cancel(string $queue, ?string $job = null): array
    {
        return $this->control->cancel($queue, $job);
    }

    /** @throws \LogicException when the driver can't reorder */
    public function prioritise(string $queue, string $job): int
    {
        return $this->control->prioritise($queue, $job);
    }

    /** The REST shape of one queue row of `QueueInspector::queues()`. @param array<string, mixed> $row @return array<string, mixed> */
    public static function payload(array $row): array
    {
        return [
            'queue' => $row['queue'],
            'state' => $row['state'],
            'paused' => $row['paused'],
            'pausable' => Queues::pausable($row['queue']),
            'waiting' => $row['waiting'],
            'delayed' => $row['delayed'],
            'running' => $row['running'],
            'oldest_waiting_at' => $row['oldest_waiting']?->toIso8601String(),
            'oldest_age_seconds' => $row['oldest_age'],
            'workers' => $row['workers'],
            'worker' => $row['worker'],
            'failed_24h' => $row['failed_24h'],
            'last_progress_at' => $row['last_progress']?->toIso8601String(),
            'heartbeat_at' => $row['beat']?->toIso8601String(),
            'lag_after_seconds' => $row['lag_after'],
            'lagging' => $row['lagging'],
        ];
    }
}
