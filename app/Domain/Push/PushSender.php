<?php

namespace App\Domain\Push;

use App\Models\PushSubscription;

/** Sends one payload to one browser. The real one talks to the push services; tests bind a fake. */
interface PushSender
{
    /** @param  array<string, mixed>  $payload */
    public function send(PushSubscription $subscription, array $payload): PushResult;
}
