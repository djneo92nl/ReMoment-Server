<?php

namespace App\Jobs;

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
 * Builds the SD card artwork zip (see SdCardExport): byte copies of the
 * 320×320 cover and 1024×600 background of the last N played albums and of
 * every source logo, under remoment/covers/ and remoment/backgrounds/.
 */
class BuildSdCardExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly ?int $limit = null) {}

    /** @return array{built_at: string, bytes: int, albums: int, logos: int, skipped: int, limit: int} */
    public function handle(): array
    {
        try {
            return $this->build();
        } finally {
            SdCardExport::clearPending();
        }
    }

    public function failed(): void
    {
        SdCardExport::clearPending();
    }

    private function build(): array
    {
        $limit = $this->limit ?? (int) config('artwork.recent_albums');
        $public = Storage::disk('public');
        $local = Storage::disk('local');

        $logoUrls = collect(SourceLogo::KEYS)->each(fn (string $key) => NowPlayingArtwork::logo($key))->map(fn (string $key) => SourceLogo::url($key));
        $albumUrls = LibraryArtwork::recentCoverUrls($limit)->reject(fn (string $url) => SourceLogo::isLogoUrl($url));

        $local->makeDirectory(dirname(SdCardExport::ZIP_PATH));
        $tmp = $local->path(SdCardExport::ZIP_PATH.'.tmp');
        @unlink($tmp);

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create {$tmp}");
        }

        $counts = ['albums' => 0, 'logos' => 0, 'skipped' => 0];

        foreach ([['logos', $logoUrls], ['albums', $albumUrls]] as [$type, $urls]) {
            foreach ($urls as $url) {
                $cover = LibraryArtwork::path($url, 'proxy_320');
                $background = LibraryArtwork::path($url, 'proxy_bg');

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

        rename($tmp, $local->path(SdCardExport::ZIP_PATH));

        $meta = [
            'built_at' => now()->toIso8601String(),
            'bytes' => (int) filesize($local->path(SdCardExport::ZIP_PATH)),
            ...$counts,
            'limit' => $limit,
        ];
        $local->put(SdCardExport::META_PATH, json_encode($meta, JSON_PRETTY_PRINT));

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

        return <<<TXT
        ReMoment artwork for the touch client's SD card
        ===============================================

        Extract this zip at the ROOT of the SD card (overwrite existing files),
        so the card contains:

          /remoment/covers/{hash}.jpg       320x320 cover
          /remoment/backgrounds/{hash}.jpg  1024x600 blurred background

        {hash} is the md5 the server uses in its artwork URLs
        (/storage/artwork/{hash}/...), so the client finds these files when the
        album or source plays.

        Built {$built}: {$counts['albums']} recently played albums and {$counts['logos']} source logos.

        TXT;
    }
}
