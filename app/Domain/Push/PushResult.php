<?php

namespace App\Domain\Push;

/** @param  bool  $gone  the push service says the subscription no longer exists (404/410): delete it */
final readonly class PushResult
{
    public function __construct(public bool $ok, public bool $gone = false, public string $reason = '') {}
}
