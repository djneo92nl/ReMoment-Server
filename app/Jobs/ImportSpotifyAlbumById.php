<?php

namespace App\Jobs;

use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Services\SpotifyTokenService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;

/** Imports one Spotify album into the library; queued per album when an artist's albums are added. */
class ImportSpotifyAlbumById implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly string $spotifyAlbumId) {}

    public function uniqueId(): string
    {
        return $this->spotifyAlbumId;
    }

    /** Released, not failed, while over the limit (the `spotify` limiter). */
    public function middleware(): array
    {
        return [new RateLimited('spotify')];
    }

    public function handle(SpotifyTokenService $tokenService, SpotifyLibraryImporter $importer): void
    {
        if ($tokenService->isConnected()) {
            $importer->importAlbum($this->spotifyAlbumId);
        }
    }
}
