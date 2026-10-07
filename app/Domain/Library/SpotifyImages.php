<?php

namespace App\Domain\Library;

final class SpotifyImages
{
    /** The smallest image at least $minWidth wide, else the largest; null without a usable image. */
    public static function pick(array $images, int $minWidth): ?string
    {
        $images = array_values(array_filter($images, fn ($image) => !empty($image['url'])));
        usort($images, fn ($a, $b) => ($a['width'] ?? 0) <=> ($b['width'] ?? 0));

        foreach ($images as $image) {
            if (($image['width'] ?? 0) >= $minWidth) {
                return $image['url'];
            }
        }

        return $images ? end($images)['url'] : null;
    }
}
