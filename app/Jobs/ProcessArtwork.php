<?php

namespace App\Jobs;

use App\Domain\Artwork\ArtworkCache;
use App\Models\Media\Album;
use ColorThief\ColorThief;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ProcessArtwork implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly string $originalUrl) {}

    public function handle(): void
    {
        if (ArtworkCache::has($this->originalUrl)) {
            return;
        }

        $hash = md5($this->originalUrl);
        $dir = "artwork/{$hash}";

        $imageData = Http::timeout(30)->get($this->originalUrl)->throw()->body();

        $manager = new ImageManager(new Driver);

        foreach ([512, 320] as $size) {
            $encoded = $manager->read($imageData)->cover($size, $size)->toJpeg(85);
            Storage::disk('public')->put("{$dir}/{$size}.jpg", $encoded);
        }

        $path512 = Storage::disk('public')->path("{$dir}/512.jpg");
        $palette = ColorThief::getPalette($path512, 5);

        $colors = array_map(
            fn (array $rgb) => sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]),
            $palette
        );

        $safeColors = array_map(fn (string $hex) => $this->ensureL($hex, 0.65), $colors);

        ArtworkCache::put($this->originalUrl, [
            'proxy_512' => Storage::disk('public')->url("{$dir}/512.jpg"),
            'proxy_320' => Storage::disk('public')->url("{$dir}/320.jpg"),
            'colors' => $colors,
            'safe_colors' => $safeColors,
        ]);

        Album::whereJsonContains('images', $this->originalUrl)->update(['colors' => $colors]);
    }

    private function ensureL(string $hex, float $minL): string
    {
        $r = hexdec(substr($hex, 1, 2)) / 255;
        $g = hexdec(substr($hex, 3, 2)) / 255;
        $b = hexdec(substr($hex, 5, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d   = $max - $min;
        $l   = ($max + $min) / 2;
        $h   = 0.0;
        $s   = 0.0;

        if ($d > 0) {
            $s = $d / (1 - abs(2 * $l - 1));
            if ($max === $r)      $h = fmod(($g - $b) / $d, 6) / 6;
            elseif ($max === $g)  $h = (($b - $r) / $d + 2) / 6;
            else                  $h = (($r - $g) / $d + 4) / 6;
            if ($h < 0) $h += 1;
        }

        if ($l >= $minL) {
            return $hex;
        }

        $l = $minL;
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h * 6, 2) - 1));
        $m = $l - $c / 2;

        [$ro, $go, $bo] = match ((int) floor($h * 6) % 6) {
            0 => [$c, $x, 0],
            1 => [$x, $c, 0],
            2 => [0,  $c, $x],
            3 => [0,  $x, $c],
            4 => [$x, 0,  $c],
            default => [$c, 0, $x],
        };

        return sprintf(
            '#%02x%02x%02x',
            min(255, (int) round(($ro + $m) * 255)),
            min(255, (int) round(($go + $m) * 255)),
            min(255, (int) round(($bo + $m) * 255))
        );
    }
}
