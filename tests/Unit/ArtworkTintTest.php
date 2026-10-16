<?php

namespace Tests\Unit;

use App\Domain\Artwork\ArtworkTint;
use App\Domain\Artwork\Hsl;
use PHPUnit\Framework\TestCase;

class ArtworkTintTest extends TestCase
{
    /** WCAG relative luminance of a #rrggbb colour. */
    private function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function ($c) {
            $c = hexdec($c) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function contrast(string $a, string $b): float
    {
        [$hi, $lo] = [max($this->luminance($a), $this->luminance($b)), min($this->luminance($a), $this->luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    public function test_no_usable_colour_gives_no_tint(): void
    {
        $this->assertNull(ArtworkTint::for(null));
        $this->assertNull(ArtworkTint::for([]));
        $this->assertNull(ArtworkTint::for(['not a colour', 42]));
        $this->assertSame('', ArtworkTint::style([]));
    }

    public function test_the_first_valid_colour_leads(): void
    {
        $tint = ArtworkTint::for(['nope', '#2255cc', '#cc2222']);

        [$hue] = Hsl::fromHex($tint['dark']);
        $this->assertEqualsWithDelta(Hsl::fromHex('#2255cc')[0], $hue, 6);
    }

    /** @dataProvider covers */
    public function test_ink_always_reads_on_both_variants(string $cover): void
    {
        $tint = ArtworkTint::for([$cover]);

        $this->assertGreaterThanOrEqual(4.5, $this->contrast($tint['dark'], $tint['ink_dark']), "dark tint of $cover");
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($tint['light'], $tint['ink_light']), "light tint of $cover");
        // deep stays deep and pastel stays pastel: fitting only moves away from the ink
        $this->assertLessThanOrEqual(0.31, Hsl::fromHex($tint['dark'])[2]);
        $this->assertGreaterThanOrEqual(0.89, Hsl::fromHex($tint['light'])[2]);
    }

    public static function covers(): array
    {
        return array_map(fn ($c) => [$c], ['#000000', '#ffffff', '#ffff00', '#00ffff', '#ff0000', '#0000ff', '#808080', '#f5deb3', '#1a2b3c', '#ff00ff']);
    }

    public function test_style_carries_both_variants_for_the_tint_class(): void
    {
        $style = ArtworkTint::style(['#336699']);

        foreach (['--tint-dark:#', '--tint-light:#', '--ink-dark:#', '--ink-light:#'] as $part) {
            $this->assertStringContainsString($part, $style);
        }
    }
}
