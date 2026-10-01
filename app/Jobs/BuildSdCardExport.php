<?php

namespace App\Jobs;

use App\Domain\Artwork\ArtworkBackgrounds;
use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\NowPlayingArtwork;
use App\Domain\Artwork\SdCardExport;
use App\Domain\Artwork\SourceLogo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Builds the SD card artwork zip for one background size (see SdCardExport):
 * byte copies of the 320×320 cover and that size's background of the last N
 * played albums and of every source logo, under remoment/covers/ and
 * remoment/backgrounds/.
 */
class BuildSdCardExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public readonly ?int $limit = null,
        public readonly string $size = ArtworkBackgrounds::DEFAULT,
    ) {}

    /** One build per size at a time; different sizes may build side by side. */
    public function uniqueId(): string
    {
        return $this->size;
    }

    /** @return array{built_at: string, bytes: int, albums: int, logos: int, skipped: int, limit: int, size: string} */
    public function handle(): array
    {
        try {
            return $this->build();
        } finally {
            SdCardExport::clearPending($this->size);
        }
    }

    public function failed(): void
    {
        SdCardExport::clearPending($this->size);
    }

    private function build(): array
    {
        if (!ArtworkBackgrounds::isSize($this->size)) {
            throw new RuntimeException("Unknown background size {$this->size}");
        }

        $limit = $this->limit ?? (int) config('artwork.recent_albums');
        $zipPath = SdCardExport::zipPath($this->size);
        $backgroundKey = ArtworkBackgrounds::key($this->size);
        $public = Storage::disk('public');
        $local = Storage::disk('local');

        $logoUrls = collect(SourceLogo::KEYS)->each(fn (string $key) => NowPlayingArtwork::logo($key))->map(fn (string $key) => SourceLogo::url($key));
        $albumUrls = LibraryArtwork::recentCoverUrls($limit)->reject(fn (string $url) => SourceLogo::isLogoUrl($url));

        $local->makeDirectory(dirname($zipPath));
        $tmp = $local->path($zipPath.'.tmp');
        @unlink($tmp);

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create {$tmp}");
        }

        $counts = ['albums' => 0, 'logos' => 0, 'skipped' => 0];

        foreach ([['logos', $logoUrls], ['albums', $albumUrls]] as [$type, $urls]) {
            foreach ($urls as $url) {
                $cover = LibraryArtwork::path($url, 'proxy_320');
                $background = LibraryArtwork::path($url, $backgroundKey);

                if (!$public->exists($cover) || !$public->exists($background)) {
                    $counts['skipped']++;

                    continue;
                }

                $hash = md5($url);
                $this->addStored($zip, $public->path($cover), sprintf(SdCardExport::COVER_ENTRY, $hash));
                $this->addStored($zip, $public->path($background), sprintf(SdCardExport::BACKGROUND_ENTRY, $hash));
                $counts[$type]++;
            }
        }

        $zip->addFromString('README.txt', $this->readme($counts));
        if (!$zip->close()) {
            throw new RuntimeException("Cannot write {$tmp}");
        }

        rename($tmp, $local->path($zipPath));

        $meta = [
            'built_at' => now()->toIso8601String(),
            'bytes' => (int) filesize($local->path($zipPath)),
            ...$counts,
            'limit' => $limit,
            'size' => $this->size,
        ];
        $local->put(SdCardExport::metaPath($this->size), json_encode($meta, JSON_PRETTY_PRINT));

        return $meta;
    }

    /** JPEGs don't compress: store them as-is (also keeps the bytes identical). */
    private function addStored(ZipArchive $zip, string $source, string $entry): void
    {
        $zip->addFile($source, $entry);
        $zip->setCompressionName($entry, ZipArchive::CM_STORE);
    }

    private function readme(array $counts): string
    {
        $built = now()->toDayDateTimeString();
        $screen = ArtworkBackgrounds::SIZES[$this->size];

        return <<<TXT
        ReMoment artwork for the touch client's SD card ({$this->size}, {$screen})
        ===============================================

        Extract this zip at the ROOT of the SD card (overwrite existing files),
        so the card contains:

          /remoment/covers/{hash}.jpg       320x320 cover
          /remoment/backgrounds/{hash}.jpg  {$this->size} blurred background

        {hash} is the md5 the server uses in its artwork URLs
        (/storage/artwork/{hash}/...), so the client finds these files when the
        album or source plays.

        Built {$built}: {$counts['albums']} recently played albums and {$counts['logos']} source logos.

        TXT;
    }
}
