<?php

namespace Tests\Support;

use App\Domain\Radio\Relay\RelayProcess;

/** A process that is "running" until the test says it ended. */
final class FakeRelayProcess implements RelayProcess
{
    public bool $alive = true;

    public bool $stopped = false;

    public function __construct(public readonly array $command) {}

    public function running(): bool
    {
        return $this->alive;
    }

    public function stop(): void
    {
        $this->alive = false;
        $this->stopped = true;
    }

    public function exitCode(): ?int
    {
        return $this->alive ? null : 1;
    }

    public function tail(): string
    {
        return 'Server returned 403 Forbidden';
    }
}
