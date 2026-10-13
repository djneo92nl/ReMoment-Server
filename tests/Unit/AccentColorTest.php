<?php

namespace Tests\Unit;

use App\Domain\Artwork\AccentColor;
use PHPUnit\Framework\TestCase;

class AccentColorTest extends TestCase
{
    public function test_no_colors_gives_white(): void
    {
        $this->assertSame('#ffffff', AccentColor::for([]));
    }

    public function test_grey_cover_gives_white(): void
    {
        $this->assertSame('#ffffff', AccentColor::for(['#404040', '#202020']));
    }

    public function test_prefers_a_vivid_safe_color_of_another_hue(): void
    {
        $accent = AccentColor::for(['#1a2b6c'], ['#5d7fa3', '#e8a33d']);

        // The orange one, lifted a little, not the blue one.
        $this->assertGreaterThan(hexdec(substr($accent, 5, 2)), hexdec(substr($accent, 1, 2)));
    }

    public function test_falls_back_to_the_complementary_hue(): void
    {
        // Blue background, only blue safe colors: the accent turns orange-ish (red channel dominant).
        $accent = AccentColor::for(['#1a2b6c'], ['#5d7fa3']);

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $accent);
        $this->assertGreaterThan(hexdec(substr($accent, 5, 2)), hexdec(substr($accent, 1, 2)));
    }
}
