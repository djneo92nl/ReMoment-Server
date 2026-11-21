<?php

namespace App\Jobs;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Library\Enrichment;
use App\Models\Media\Album;
use App\Services\MetadataHttp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

/**
 * Replaces an album's cover with the front image of its MusicBrainz release from the Cover Art Archive: what accepting a
 * cover suggestion does (App\Domain\Health\Suggestions). No front image means nothing changes. Rate limits and outages
 * throw, so the job is retried; the image then goes through ProcessArtwork like every cover.
 */
class ReplaceCoverFromArchive implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly Album $album) {}

    public function uniqueId(): string
    {
        return (string) $this->album->id;
    }

    public function middleware(): array
    {
        return [new RateLimited('musicbrainz')];
    }

    public function handle(): void
    {
        $release = Enrichment::mbid($this->album);

        if (!$release) {
            return;
        }

        $response = MetadataHttp::get("https://coverartarchive.org/release/{$release}");
        $front = collect($response->ok() ? $response->json('images', []) : [])->first(fn ($image) => $image['front'] ?? false);
        $url = $front['thumbnails']['1200'] ?? $front['thumbnails']['large'] ?? $front['image'] ?? null;

        if (!$url) {
            return;
        }

        $url = preg_replace('~^http://~', 'https://', $url);
        $this->album->update(['images' => [['url' => $url]]]);

        if (!ArtworkCache::has($url)) {
            ProcessArtwork::dispatch($url);
        }
    }
}
