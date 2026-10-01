<?php

namespace App\Jobs;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LogoRenderer;
use App\Domain\Artwork\SourceLogo;
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
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

class ProcessArtwork implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /** Square proxies, in pixels; each becomes `proxy_{size}` in the cache payload. */
    public const SQUARE_SIZES = [512, 320, 120];

    /**
     * Full-screen backgrounds rendered behind the cover on client displays:
     * cache payload key => [width, height]. Add a size here to support
     * another screen, e.g. 'proxy_bg_320x480' => [320, 480] for a 3.5" portrait client.
     */
    public const BACKGROUNDS = [
        'proxy_bg' => [1024, 600], // 7" ESP32-S3 touch remote (landscape)
    ];

    /**
     * JPEG quality. All JPEGs are baseline (non-progressive): the ESP32's
     * TJpgDec decoder can't read progressive files.
     */
    private const JPEG_QUALITY = 85;

    /** Percentage of the blurred cover's brightness left after the black overlay. */
    private const BACKGROUND_BRIGHTNESS = 40;

    /**
     * @param  string  $originalUrl  An image URL, or a SourceLogo pseudo URL
     *                               for a generated logo (drawn locally, not downloaded).
     */
    public function __construct(public readonly string $originalUrl) {}

    public function handle(): void
    {
        if (ArtworkCache::has($this->originalUrl)) {
            $this->applyColorsToAlbums(ArtworkCache::get($this->originalUrl)['colors'] ?? []);

            return;
        }

        $hash = md5($this->originalUrl);
        $dir = "artwork/{$hash}";
        $disk = Storage::disk('public');

        $imageData = $this->imageData();

        $manager = new ImageManager(new Driver);
        $payload = [];

        foreach (self::SQUARE_SIZES as $size) {
            $path = "{$dir}/{$size}.jpg";
            $disk->put($path, $this->encodeJpeg($manager->read($imageData)->cover($size, $size)));
            $payload["proxy_{$size}"] = $disk->url($path);
        }

        foreach (self::BACKGROUNDS as $key => [$width, $height]) {
            $path = "{$dir}/bg_{$width}x{$height}.jpg";
            $disk->put($path, $this->renderBackground($manager, $imageData, $width, $height));
            $payload[$key] = $disk->url($path);
        }

        $palette = ColorThief::getPalette($disk->path("{$dir}/512.jpg"), 5);

        $colors = array_map(
            fn (array $rgb) => sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]),
            $palette
        );

        $payload['colors'] = $colors;
        $payload['safe_colors'] = array_map(fn (string $hex) => $this->ensureL($hex, 0.65), $colors);

        ArtworkCache::put($this->originalUrl, $payload);

        $this->applyColorsToAlbums($colors);
    }

    /**
     * The cover scaled to fill the screen, heavily blurred and darkened, so a
     * sharp cover and white text sit on top of it with good contrast.
     */
    private function renderBackground(ImageManager $manager, string $imageData, int $width, int $height): string
    {
        // Blurring at full size with GD's 3x3 kernel would take hundreds of
        // passes. Instead shrink to 1/32, blur, and scale back up in doubling
        // steps with a light blur after each, which hides GD's blocky upscaling.
        $w = max(1, intdiv($width, 32));
        $h = max(1, intdiv($height, 32));
        $image = $manager->read($imageData)->cover($w, $h)->blur(3);

        while ($w < $width || $h < $height) {
            $w = min($width, $w * 2);
            $h = min($height, $h * 2);
            $image->resize($w, $h)->blur(2);
        }

        $overlay = $manager->create($width, $height)->fill('000000');
        $image->place($overlay, 'top-left', 0, 0, 100 - self::BACKGROUND_BRIGHTNESS);

        return $this->encodeJpeg($image);
    }

    private function imageData(): string
    {
        $logo = SourceLogo::keyFromUrl($this->originalUrl);

        return $logo !== null
            ? LogoRenderer::render($logo)
            : Http::timeout(30)->get($this->originalUrl)->throw()->body();
    }

    private function encodeJpeg(ImageInterface $image): string
    {
        return (string) $image->encode(new JpegEncoder(quality: self::JPEG_QUALITY, progressive: false));
    }

    private function applyColorsToAlbums(array $colors): void
    {
        if (empty($colors) || SourceLogo::isLogoUrl($this->originalUrl)) {
            return;
        }

        // json_encode() (used by Eloquent's array cast) escapes "/" as "\/",
        // so the LIKE pattern must match the escaped form actually stored.
        $likeValue = str_replace('/', '\/', $this->originalUrl);

        Album::whereNotNull('images')
            ->where('images', 'like', '%'.$likeValue.'%')
            ->get(['id', 'images'])
            ->filter(fn (Album $album) => in_array(
                $this->originalUrl,
                array_map(fn ($image) => is_array($image) ? ($image['url'] ?? null) : $image, $album->images ?? []),
                true
            ))
            ->each(fn (Album $album) => $album->update(['colors' => $colors]));
    }

    private function ensureL(string $hex, float $minL): string
    {
        $r = hexdec(substr($hex, 1, 2)) / 255;
        $g = hexdec(substr($hex, 3, 2)) / 255;
        $b = hexdec(substr($hex, 5, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d = $max - $min;
        $l = ($max + $min) / 2;
        $h = 0.0;
        $s = 0.0;

        if ($d > 0) {
            $s = $d / (1 - abs(2 * $l - 1));
            if ($max === $r) {
                $h = fmod(($g - $b) / $d, 6) / 6;
            } elseif ($max === $g) {
                $h = (($b - $r) / $d + 2) / 6;
            } else {
                $h = (($r - $g) / $d + 4) / 6;
            }
            if ($h < 0) {
                $h += 1;
            }
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
