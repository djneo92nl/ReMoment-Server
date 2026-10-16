<?php

namespace App\Domain\Artwork;

/**
 * The tint of a card drawn from a cover's colours (library shelves, genre tiles, hero): the cover's hue as a deep
 * surface in dark mode and a pastel one in light mode, each with an ink that always reads on it.
 *
 * The page follows `prefers-color-scheme`, so both variants are emitted as CSS custom properties and the
 * `.tint` class in app.css picks one. Each variant starts from a lightness bound (dark ≤ 0.30, light ≥ 0.90) and is
 * then nudged until the fixed ink reaches MIN_CONTRAST (HSL lightness alone isn't enough: pure yellow is luminous
 * even at 30%), so the text reads whatever the cover.
 */
final class ArtworkTint
{
    /** Ink on the deep (dark mode) and pastel (light mode) variants. */
    public const INK_DARK = '#f5f5f4';

    public const INK_LIGHT = '#1c1917';

    /** WCAG AA for body text. */
    public const MIN_CONTRAST = 4.5;

    /**
     * @param  string[]|null  $colors  the artwork's dominant colours (the first leads); null/empty/invalid → no tint
     * @return array{dark: string, light: string, ink_dark: string, ink_light: string, muted_dark: string, muted_light: string}|null
     */
    public static function for(?array $colors): ?array
    {
        foreach ($colors ?? [] as $hex) {
            $hsl = is_string($hex) ? Hsl::fromHex($hex) : null;
            if ($hsl === null) {
                continue;
            }
            [$h, $s] = $hsl;

            $dark = self::fit($h, min($s, 0.55), min($hsl[2], 0.30), self::INK_DARK, -0.02);
            $light = self::fit($h, min($s, 0.60), max($hsl[2], 0.90), self::INK_LIGHT, 0.01);

            return [
                'dark' => $dark,
                'light' => $light,
                'ink_dark' => self::INK_DARK,
                'ink_light' => self::INK_LIGHT,
                'muted_dark' => self::muted(self::INK_DARK, $dark),
                'muted_light' => self::muted(self::INK_LIGHT, $light),
            ];
        }

        return null;
    }

    /** The inline `style` value for a `.tint` element ('' without a tint, so the plain surface shows). */
    public static function style(?array $colors): string
    {
        $tint = self::for($colors);

        return $tint === null ? '' : "--tint-dark:{$tint['dark']};--tint-light:{$tint['light']};--ink-dark:{$tint['ink_dark']};--ink-light:{$tint['ink_light']};--muted-dark:{$tint['muted_dark']};--muted-light:{$tint['muted_light']}";
    }

    /** The secondary text colour: the ink blended into the surface (from 70%) as far as needed to keep MIN_CONTRAST. */
    private static function muted(string $ink, string $surface): string
    {
        for ($alpha = 0.7; $alpha < 1.0; $alpha += 0.05) {
            $hex = '#';
            for ($i = 1; $i < 7; $i += 2) {
                $hex .= sprintf('%02x', round(hexdec(substr($ink, $i, 2)) * $alpha + hexdec(substr($surface, $i, 2)) * (1 - $alpha)));
            }
            if (self::contrast($hex, $surface) >= self::MIN_CONTRAST) {
                return $hex;
            }
        }

        return $ink;
    }

    /** Moves the lightness by `$step` until `$ink` reaches MIN_CONTRAST on the colour (or the range ends). */
    private static function fit(float $h, float $s, float $l, string $ink, float $step): string
    {
        $hex = Hsl::toHex($h, $s, $l);
        while (self::contrast($hex, $ink) < self::MIN_CONTRAST && $l + $step > 0.02 && $l + $step < 0.99) {
            $l += $step;
            $hex = Hsl::toHex($h, $s, $l);
        }

        return $hex;
    }

    /** WCAG contrast ratio of two #rrggbb colours. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (string $c) {
            $c = hexdec($c) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
