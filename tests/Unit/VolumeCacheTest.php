<?php

namespace Tests\Unit;

use App\Domain\Device\Cache\Volume;
use Tests\TestCase;

class VolumeCacheTest extends TestCase
{
    public function test_remember_reads_the_device_once_and_then_serves_the_cache(): void
    {
        $reads = 0;
        $read = function () use (&$reads) {
            $reads++;

            return 42;
        };

        $this->assertSame(42, Volume::remember(9001, $read));
        $this->assertSame(42, Volume::remember(9001, $read));
        $this->assertSame(1, $reads);
    }

    public function test_a_device_reporting_no_level_is_answered_as_zero_and_not_cached(): void
    {
        $this->assertSame(0, Volume::remember(9002, fn () => null));
        $this->assertFalse(Volume::getVolume(9002));
    }

    public function test_a_cached_zero_is_a_real_level(): void
    {
        Volume::updateVolume(9003, 0);

        $this->assertSame(0, Volume::getVolume(9003));
        $this->assertSame(0, Volume::remember(9003, fn () => $this->fail('must not read the device')));
    }
}
