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

        ArtworkCache::put($this->originalUrl, [
            'proxy_512' => Storage::disk('public')->url("{$dir}/512.jpg"),
            'proxy_320' => Storage::disk('public')->url("{$dir}/320.jpg"),
            'colors' => $colors,
        ]);

        Album::whereJsonContains('images', $this->originalUrl)->update(['colors' => $colors]);
    }
}
