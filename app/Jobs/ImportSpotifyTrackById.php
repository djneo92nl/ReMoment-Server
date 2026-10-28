<?php

namespace App\Jobs;

use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Services\SpotifyTokenService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;

/** Puts one Spotify track (with its artist and album record, not the rest of the album) in the library. */
class ImportSpotifyTrackById implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly string $spotifyTrackId) {}

    public function uniqueId(): string
    {
        return $this->spotifyTrackId;
    }

    public function middleware(): array
    {
        return [new RateLimited('spotify')];
    }

    public function handle(SpotifyTokenService $tokenService, SpotifyLibraryImporter $importer): void
    {
        if ($tokenService->isConnected()) {
            $importer->importTrackById($this->spotifyTrackId);
        }
    }
}
