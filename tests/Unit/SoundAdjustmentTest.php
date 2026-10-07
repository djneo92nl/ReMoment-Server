<?php

namespace Tests\Unit;

use App\Domain\Device\Settings\AdjustmentRange;
use App\Domain\Device\Settings\SoundAdjustment;
use App\Integrations\Common\UnsupportedOperationException;
use PHPUnit\Framework\TestCase;

class SoundAdjustmentTest extends TestCase
{
    private function adjustment(): SoundAdjustment
    {
        return new SoundAdjustment(new AdjustmentRange(0, -6, 6, 2), null, null);
    }

    public function test_a_valid_change_passes_and_untouched_parts_are_ignored(): void
    {
        $this->adjustment()->assertCanSet(4, null, null);
        $this->adjustment()->assertCanSet(null, null, null);

        $this->addToAssertionCount(1);
    }

    public function test_a_value_outside_the_range_or_step_is_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bass must be between -6 and 6 (step 2).');

        $this->adjustment()->assertCanSet(3, null, null);
    }

    public function test_a_part_the_device_lacks_is_unsupported(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage('This device has no treble adjustment.');

        $this->adjustment()->assertCanSet(null, 2, null);
    }

    public function test_loudness_needs_a_device_that_has_it(): void
    {
        $this->expectException(UnsupportedOperationException::class);

        $this->adjustment()->assertCanSet(null, null, true);
    }
}
