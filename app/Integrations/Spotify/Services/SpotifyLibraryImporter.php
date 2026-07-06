<?php

namespace App\Integrations\Spotify\Services;

use App\Domain\Artwork\ArtworkCache;
use App\Jobs\EnrichTrackMetadata;
use App\Jobs\ProcessArtwork;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Services\SpotifyTokenService;

class SpotifyLibraryImporter
{
    /** @var array<string, true> */
    private array $dispatchedArtwork = [];

    public function __construct(private SpotifyTokenService $tokenService) {}

    public function importSavedTracks(): int
    {
        $api = $this->tokenService->makeApiClient();
        $count = 0;

        foreach ($this->paginate(fn ($offset, $limit) => $api->getMySavedTracks(['limit' => $limit, 'offset' => $offset])) as $item) {
            if (empty($item['track']['id'])) {
                continue;
            }

            $this->importTrackItem($item['track']);
            $count++;
        }

        return $count;
    }

    public function importPlaylists(): int
    {
        $api = $this->tokenService->makeApiClient();
        $count = 0;

        foreach ($this->paginate(fn ($offset, $limit) => $api->getMyPlaylists(['limit' => $limit, 'offset' => $offset])) as $item) {
            $this->importPlaylistItem($item, $api);
            $count++;
        }

        return $count;
    }

    private function importPlaylistItem(array $spotifyPlaylist, \SpotifyWebAPI\SpotifyWebAPI $api): void
    {
        $playlist = Playlist::updateOrCreate(
            ['external_id' => 'spotify:playlist:'.$spotifyPlaylist['id'], 'source' => 'spotify'],
            [
                'name' => $spotifyPlaylist['name'] ?: 'Untitled Playlist',
                'description' => $spotifyPlaylist['description'] ?: null,
                'images' => $spotifyPlaylist['images'] ?? [],
            ],
        );

        Metadata::updateOrCreate(
            [
                'metadatable_type' => Playlist::class,
                'metadatable_id' => $playlist->id,
                'key' => 'spotify_owner',
            ],
            [
                'value' => $spotifyPlaylist['owner']['display_name'] ?? null,
                'type' => 'string',
                'source' => 'spotify',
            ],
        );

        $trackIds = [];
        $position = 0;

        foreach ($this->paginate(fn ($offset, $limit) => $api->getPlaylistTracks($spotifyPlaylist['id'], ['limit' => $limit, 'offset' => $offset])) as $entry) {
            if (empty($entry['track']['id'])) {
                continue;
            }

            $track = $this->importTrackItem($entry['track']);
            $trackIds[$track->id] = ['position' => $position++];
        }

        $playlist->tracks()->sync($trackIds);
    }

    private function importTrackItem(array $spotifyTrack): Track
    {
        $artistName = $spotifyTrack['artists'][0]['name'] ?? 'Unknown Artist';
        $albumName = $spotifyTrack['album']['name'] ?? 'Unknown Album';
        $images = $spotifyTrack['album']['images'] ?? [];

        $artist = Artist::firstOrCreate(['name' => $artistName, 'source' => 'spotify']);

        $album = Album::firstOrCreate(
            ['artist_id' => $artist->id, 'name' => $albumName, 'source' => 'spotify'],
            [
                'images' => $images,
                'released_at' => $spotifyTrack['album']['release_date'] ?? null,
            ],
        );

        $this->maybeProcessArtwork($images[0]['url'] ?? null);

        $track = Track::updateOrCreate(
            ['external_id' => 'spotify:track:'.$spotifyTrack['id'], 'source' => 'spotify'],
            [
                'album_id' => $album->id,
                'artist_id' => $artist->id,
                'name' => $spotifyTrack['name'] ?: 'Unknown Track',
                'duration' => isset($spotifyTrack['duration_ms']) ? (int) round($spotifyTrack['duration_ms'] / 1000) : null,
                'images' => $images,
            ],
        );

        if ($track->wasRecentlyCreated) {
            EnrichTrackMetadata::dispatch($track);
        }

        return $track;
    }

    private function maybeProcessArtwork(?string $url): void
    {
        if (!$url || isset($this->dispatchedArtwork[$url]) || ArtworkCache::has($url)) {
            return;
        }

        $this->dispatchedArtwork[$url] = true;
        ProcessArtwork::dispatch($url);
    }

    private function paginate(callable $fetch): \Generator
    {
        $offset = 0;
        $limit = 50;

        do {
            $page = $fetch($offset, $limit);
            $items = $page['items'] ?? [];

            foreach ($items as $item) {
                yield $item;
            }

            $offset += $limit;
        } while ($offset < ($page['total'] ?? 0));
    }
}
