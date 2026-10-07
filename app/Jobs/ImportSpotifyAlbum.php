<?php

namespace App\Jobs;

use App\Domain\Library\SpotifyUri;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Album;
use App\Models\Media\Metadata;
use App\Services\SpotifyTokenService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A track played from Spotify creates its album with that one track; this adds the rest of the album.
 * Done once per album (`spotify_album_imported_at`).
 */
class ImportSpotifyAlbum implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Album $album, public readonly string $spotifyTrackId) {}

    public function uniqueId(): string
    {
        return (string) $this->album->id;
    }

    public function handle(SpotifyTokenService $tokenService, SpotifyLibraryImporter $importer): void
    {
        if (!$tokenService->isConnected() || $this->album->metadata()->where('key', 'spotify_album_imported_at')->exists()) {
            return;
        }

        $spotifyAlbumId = $this->spotifyAlbumId($tokenService);

        if ($spotifyAlbumId !== null) {
            $importer->importAlbum($spotifyAlbumId);
        }

        Metadata::updateOrCreate(
            ['metadatable_type' => $this->album->getMorphClass(), 'metadatable_id' => $this->album->id, 'key' => 'spotify_album_imported_at'],
            ['value' => now()->toIso8601String(), 'type' => 'string', 'source' => 'spotify'],
        );
    }

    private function spotifyAlbumId(SpotifyTokenService $tokenService): ?string
    {
        $stored = $this->album->metadata()->where('key', 'spotify_album_uri')->value('value');
        if ($id = SpotifyUri::albumId($stored)) {
            return $id;
        }

        return $tokenService->makeApiClient()->getTrack($this->spotifyTrackId)['album']['id'] ?? null;
    }
}
