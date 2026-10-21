<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\LiteArtwork;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Tiny cover images for old clients; see LiteArtwork and docs/api/players.md. */
class LiteArtworkController extends Controller
{
    public function show(Request $request, string $hash, int $size, string $format): Response
    {
        abort_unless(in_array($size, LiteArtwork::SIZES, true), 404);

        $colors = (int) $request->query('colors', LiteArtwork::DEFAULT_COLORS);
        abort_unless(in_array($colors, LiteArtwork::COLORS, true), 422, 'colors must be one of '.implode(', ', LiteArtwork::COLORS));

        $bytes = LiteArtwork::get($hash, $size, $colors, $format);
        abort_if($bytes === null, 404, 'Artwork is not processed yet.');

        return response($bytes, 200, [
            'Content-Type' => LiteArtwork::FORMATS[$format],
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
