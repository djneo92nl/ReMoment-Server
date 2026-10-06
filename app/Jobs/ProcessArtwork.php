<?php

namespace App\Jobs;

use App\Domain\Artwork\ArtworkBackgrounds;
use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LogoRenderer;
use App\Domain\Artwork\PlaylistArtwork;
use App\Domain\Artwork\PlaylistCompositeRenderer;
use App\Domain\Artwork\SourceLogo;
use App\Domain\Device\DeviceCache;
use App\Listeners\Device\PublishNowPlayingToMqtt;
use App\Models\Device;
use App\Models\Media\Album;
use ColorThief\ColorThief;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * JPEG quality. All JPEGs are baseline (non-progressive): the ESP32's
     * TJpgDec decoder can't read progressive files.
     */
    private const JPEG_QUALITY = 85;

    /** Backgrounds get more quality so the dithering noise survives the JPEG. */
    private const BACKGROUND_JPEG_QUALITY = 92;

    /** Peak amplitude (of 255) of the noise added to backgrounds: about one RGB565 step. */
    private const BACKGROUND_DITHER = 4;

    /** Percentage of the blurred cover's brightness left after the black overlay. */
    private const BACKGROUND_BRIGHTNESS = 40;

    /**
     * @param  string  $originalUrl  An image URL, a SourceLogo pseudo URL
     *                               for a generated logo (drawn locally, not downloaded),
     *                               or a PlaylistArtwork composite pseudo URL.
     * @param  array<int, string>  $sources  For a composite: the 4 cover URLs it is made of
     *                                       (its URL is their hash, so they can't be derived from it).
     */
    public function __construct(public readonly string $originalUrl, public readonly array $sources = []) {}

    public function handle(): void
    {
        if (PlaylistArtwork::isCompositeUrl($this->originalUrl) && !PlaylistArtwork::matches($this->originalUrl, $this->sources)) {
            Log::warning("ProcessArtwork: skipped playlist composite {$this->originalUrl} without its matching covers.");

            return;
        }

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

        // Full-screen backgrounds behind the cover on the client displays (ArtworkBackgrounds).
        foreach (array_keys(ArtworkBackgrounds::SIZES) as $size) {
            [$width, $height] = ArtworkBackgrounds::dimensions($size);
            $path = "{$dir}/".ArtworkBackgrounds::file($size);
            $disk->put($path, $this->renderBackground($manager, $imageData, $width, $height));
            $payload[ArtworkBackgrounds::key($size)] = $disk->url($path);
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
        $this->republishNowPlaying();
    }

    /**
     * The first play of a new cover goes out on MQTT without artwork (it's processed here,
     * asynchronously). Re-send /data for every device still playing it, so clients get the
     * artwork now instead of on the next track.
     */
    private function republishNowPlaying(): void
    {
        $publisher = app(PublishNowPlayingToMqtt::class);

        foreach (Device::pluck('id') as $deviceId) {
            $nowPlaying = DeviceCache::getNowPlaying($deviceId);
            if ($nowPlaying !== null && ArtworkCache::extractImageUrl($nowPlaying) === $this->originalUrl) {
                $publisher->publish($deviceId, $nowPlaying);
            }
        }
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

        $this->addDither($image);

        return $this->encodeJpeg($image, self::BACKGROUND_JPEG_QUALITY);
    }

    /**
     * A blurred, darkened cover is a very smooth ramp over few levels, which
     * bands after JPEG and the clients' RGB565 displays. Fine noise before
     * encoding makes their truncation act as dithering instead.
     */
    private function addDither(ImageInterface $image): void
    {
        $gd = $image->core()->native();
        $width = imagesx($gd);
        $height = imagesy($gd);
        $amp = self::BACKGROUND_DITHER;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($gd, $x, $y);
                $n = mt_rand(-$amp, $amp);
                $r = min(255, max(0, (($rgb >> 16) & 0xFF) + $n));
                $g = min(255, max(0, (($rgb >> 8) & 0xFF) + $n));
                $b = min(255, max(0, ($rgb & 0xFF) + $n));
                imagesetpixel($gd, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }
    }

    private function imageData(): string
    {
        if (PlaylistArtwork::isCompositeUrl($this->originalUrl)) {
            return PlaylistCompositeRenderer::render($this->sources);
        }

        $logo = SourceLogo::keyFromUrl($this->originalUrl);

        return $logo !== null
            ? LogoRenderer::render($logo)
            : Http::timeout(30)->get($this->originalUrl)->throw()->body();
    }

    private function encodeJpeg(ImageInterface $image, int $quality = self::JPEG_QUALITY): string
    {
        return (string) $image->encode(new JpegEncoder(quality: $quality, progressive: false));
    }

    private function applyColorsToAlbums(array $colors): void
    {
        if (empty($colors) || SourceLogo::isLogoUrl($this->originalUrl) || PlaylistArtwork::isCompositeUrl($this->originalUrl)) {
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
