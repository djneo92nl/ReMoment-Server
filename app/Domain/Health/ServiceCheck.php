<?php

namespace App\Domain\Health;

/** The outcome of probing one external service. `status` is ok | warning | error | disabled. */
final readonly class ServiceCheck
{
    private function __construct(
        public string $status,
        public string $message,
    ) {}

    public static function ok(string $message = 'Working'): self
    {
        return new self('ok', $message);
    }

    /** Reachable but degraded: rate limited, a scope missing, a credential half set up. */
    public static function warning(string $message): self
    {
        return new self('warning', $message);
    }

    public static function error(string $message): self
    {
        return new self('error', $message);
    }

    /** Not configured, so nothing to check (and the app skips the service). */
    public static function disabled(string $message): self
    {
        return new self('disabled', $message);
    }

    /** @return array{status: string, message: string} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'message' => $this->message];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['status'], $data['message']);
    }
}
