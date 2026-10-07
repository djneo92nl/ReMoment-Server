<?php

namespace Tests\Unit;

use App\Integrations\Common\ListenerBackoff;
use PHPUnit\Framework\TestCase;

class ListenerBackoffTest extends TestCase
{
    public function test_it_doubles_per_failure_up_to_the_cap_and_resets_after_a_clean_end(): void
    {
        $backoff = new ListenerBackoff(maxSeconds: 30);

        $this->assertSame([2, 4, 8, 16, 30, 30], array_map(fn () => $backoff->next(failed: true), range(1, 6)));
        $this->assertSame(1, $backoff->next(failed: false));
        $this->assertSame(2, $backoff->next(failed: true));
    }
}
