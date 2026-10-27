<?php

namespace App\Jobs;

use App\Domain\Health\Heartbeat;
use App\Domain\Health\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Proves a worker of one queue picks jobs up. One per queue and minute, but only one waiting at a time (unique):
 * behind a long scan or analysis the next beat simply waits, and /settings/health calls that queue busy, not stuck.
 */
class QueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    /** `health` has its own queue, polled first by the default worker, so a backlog of real jobs can't make the worker look dead. */
    public function __construct(public readonly string $queueName = Queues::HEALTH)
    {
        $this->onQueue($queueName);
        $this->uniqueFor = $queueName === Queues::HEALTH ? 300 : 7200;
    }

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return $this->queueName;
    }

    public function handle(): void
    {
        Heartbeat::beat(Queues::heartbeatKey($this->queueName));
    }
}
