<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** QR codes as inline SVG (no image extension needed, prints sharp). */
final class QrCode
{
    public static function svg(string $text, int $size = 220): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd)))->writeString($text);

        // Inline in a page: without the XML declaration.
        return trim(preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg) ?? $svg);
    }
}
