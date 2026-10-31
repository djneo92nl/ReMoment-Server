<?php

namespace Tests\Unit;

use App\Domain\Analysis\StemLanes;
use PHPUnit\Framework\TestCase;

class StemLanesTest extends TestCase
{
    public function test_a_lane_runs_from_its_own_loudest_one_percent_down_forty_db(): void
    {
        // 200 frames: 10 at the top, 190 spread below it; the 99th percentile is the top level.
        $loud = array_merge(array_fill(0, 10, -10), array_fill(0, 100, -30), array_fill(0, 90, -60));
        $quiet = array_merge(array_fill(0, 10, -40), array_fill(0, 100, -60), array_fill(0, 90, -90));

        $h = StemLanes::heights($loud, 200);
        $this->assertSame([1.0, 0.5, 0.0], [$h[0], $h[10], $h[199]]);

        $q = StemLanes::heights($quiet, 200);
        $this->assertSame(1.0, $q[0], 'a quiet stem still reaches the top of its own lane');
        $this->assertSame(0.5, $q[10]);
        $this->assertSame(0.0, $q[199]);
    }

    public function test_a_silent_lane_is_flat(): void
    {
        $this->assertSame([0.0, 0.0, 0.0], StemLanes::heights([-80, -80, -80]));
        $this->assertSame([], StemLanes::heights([]));
    }

    public function test_a_long_curve_is_reduced_to_the_loudest_frame_of_each_column(): void
    {
        $curve = array_merge(array_fill(0, 100, -60), array_fill(0, 5, -20), array_fill(0, 95, -60));   // 200 frames, a loud burst in the middle
        $heights = StemLanes::heights($curve, 4);

        $this->assertSame([0.0, 0.0, 1.0, 0.0], $heights, 'the loud burst survives the reduction');
    }

    public function test_the_path_is_a_closed_area_on_the_baseline(): void
    {
        $this->assertSame('M0 100H0 V0H1H1 V50H2V100Z', StemLanes::path([1.0, 0.5]));
        $this->assertSame('', StemLanes::path([]));
        $this->assertSame('51 %', StemLanes::percent(0.506));
    }
}
