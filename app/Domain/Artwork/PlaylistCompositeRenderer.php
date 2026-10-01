<?php

namespace App\Domain\Artwork;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use InvalidArgumentException;

/**
 * Draws a PlaylistArtwork composite: 4 album covers in a 2×2 mosaic, in
 * tile order left to right, top to bottom. A cover already processed is
 * read from its 512 proxy on disk; any other is downloaded directly, so a
 * composite never waits for its covers' own ProcessArtwork jobs.
 */
final class PlaylistCompositeRenderer
{
    /** Side of the composite, in pixels: 2× the largest square proxy. */
    public const SIZE = 1024;

    /** PNG bytes of the composite (lossless; ProcessArtwork encodes the JPEGs). */
    public static function render(array $sources): string
    {
        if (count($sources) !== PlaylistArtwork::TILES) {
            throw new InvalidArgumentException('A playlist composite needs exactly '.PlaylistArtwork::TILES.' covers.');
        }

        $manager = new ImageManager(new Driver);
        $tile = intdiv(self::SIZE, 2);
        $canvas = $manager->create(self::SIZE, self::SIZE)->fill('181818');

        foreach (array_values($sources) as $i => $url) {
            $cover = $manager->read(self::coverData($url))->cover($tile, $tile);
            $canvas->place($cover, 'top-left', ($i % 2) * $tile, intdiv($i, 2) * $tile);
        }

        return (string) $canvas->toPng();
    }

    private static function coverData(string $url): string
    {
        $disk = Storage::disk('public');
        $processed = 'artwork/'.md5($url).'/512.jpg';

        if ($disk->exists($processed)) {
            return $disk->get($processed);
        }

        $logo = SourceLogo::keyFromUrl($url);

        return $logo !== null
            ? LogoRenderer::render($logo)
            : Http::timeout(30)->get($url)->throw()->body();
    }
}
