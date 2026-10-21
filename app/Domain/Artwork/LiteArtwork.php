<?php

namespace App\Domain\Artwork;

use Illuminate\Support\Facades\Storage;

/**
 * Tiny cover images for old or small screens (Palm OS, Pocket PC, a C64 converter): a square, a few
 * dozen to 160 pixels, GIF or 8-bit BMP with a small palette, or a PGM greyscale. They are cut from the
 * 320 px cover ProcessArtwork already made (`artwork/{md5 of the cover URL}/320.jpg` on the public disk),
 * on first request, and kept next to it (`lite/{size}-{colors}.{ext}`), so nothing is made ahead of time.
 * Sizes and palette sizes are whitelisted, so the number of files per cover is bounded.
 */
final class LiteArtwork
{
    public const SIZES = [32, 48, 64, 96, 120, 160];

    public const FORMATS = ['gif' => 'image/gif', 'bmp' => 'image/bmp', 'pgm' => 'image/x-portable-graymap'];

    /** Colors (grey levels for a PGM) a client can ask for. */
    public const COLORS = [2, 4, 16, 64, 256];

    public const DEFAULT_COLORS = 16;

    private const SOURCE = '320.jpg';

    public static function path(string $hash, int $size, int $colors, string $format): string
    {
        return "artwork/{$hash}/lite/{$size}-{$colors}.{$format}";
    }

    /** The image bytes, or null while the cover is not processed (the client shows its placeholder). */
    public static function get(string $hash, int $size, int $colors, string $format): ?string
    {
        $disk = Storage::disk('public');
        $path = self::path($hash, $size, $colors, $format);

        if ($disk->exists($path)) {
            return $disk->get($path);
        }

        $source = "artwork/{$hash}/".self::SOURCE;
        if (!$disk->exists($source)) {
            return null;
        }

        $bytes = self::render($disk->get($source), $size, $colors, $format);
        $disk->put($path, $bytes);

        return $bytes;
    }

    public static function render(string $jpeg, int $size, int $colors, string $format): string
    {
        $source = imagecreatefromstring($jpeg);
        if ($source === false) {
            throw new \RuntimeException('The cover is not an image.');
        }

        $image = imagecreatetruecolor($size, $size);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));

        return match ($format) {
            'pgm' => self::pgm($image, $size, $colors),
            'bmp' => self::encode(self::palette($image, $colors), fn ($im) => imagebmp($im, null, false)),
            default => self::encode(self::palette($image, $colors), fn ($im) => imagegif($im)),
        };
    }

    private static function palette(\GdImage $image, int $colors): \GdImage
    {
        imagetruecolortopalette($image, true, $colors);

        return $image;
    }

    private static function encode(\GdImage $image, callable $write): string
    {
        ob_start();
        $write($image);

        return (string) ob_get_clean();
    }

    /** Binary PGM (P5), 8 bits per pixel, quantized to `$levels` grey levels. */
    private static function pgm(\GdImage $image, int $size, int $levels): string
    {
        $rows = '';
        $steps = max(1, $levels - 1);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $grey = (0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF)) / 255;
                $rows .= chr((int) round(round($grey * $steps) * 255 / $steps));
            }
        }

        return "P5\n{$size} {$size}\n255\n".$rows;
    }
}
