<?php

namespace Tests\Unit;

use App\Domain\Helpers\TimeHelper;
use PHPUnit\Framework\TestCase;

class TimeHelperTest extends TestCase
{
    public function test_it_formats_track_lengths(): void
    {
        $this->assertSame('3:33', TimeHelper::secondsToMinutes(213));
        $this->assertSame('62:05', TimeHelper::secondsToMinutes(3725));
    }

    public function test_it_formats_totals_as_hours_and_minutes(): void
    {
        $this->assertSame('0m', TimeHelper::humanDuration(0));
        $this->assertSame('5m', TimeHelper::humanDuration(330));
        $this->assertSame('1h 2m', TimeHelper::humanDuration(3725));
    }
}
