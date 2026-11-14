<?php

namespace Tests\Support;

use App\Domain\Push\PushResult;
use App\Domain\Push\PushSender;
use App\Models\PushSubscription;

/** Records what would be pushed; answers per endpoint with a queued result (default: success). */
class FakePushSender implements PushSender
{
    /** @var list<array{endpoint: string, payload: array}> */
    public array $sent = [];

    /** @var array<string, PushResult|\Throwable> */
    public array $results = [];

    public function send(PushSubscription $subscription, array $payload): PushResult
    {
        $this->sent[] = ['endpoint' => $subscription->endpoint, 'payload' => $payload];
        $result = $this->results[$subscription->endpoint] ?? new PushResult(true);
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }
}
