<?php

namespace App\Integrations\Spotify\Services;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Library\Enrichment;
use App\Domain\Library\LibraryIdentity;
use App\Domain\Library\Normalizer;
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

    /**
     * Imports every track of a Spotify album (the album of a track that was just played), so the
     * library holds the full album instead of the one song. Returns the number of tracks imported.
     */
    public function importAlbum(string $spotifyAlbumId, ?Album $into = null): int
    {
        $api = $this->tokenService->makeApiClient();

        $spotifyAlbum = $api->getAlbum($spotifyAlbumId);
        $albumInfo = array_diff_key($spotifyAlbum, ['tracks' => true]);
        $count = 0;
        $album = null;

        foreach ($this->paginate(fn ($offset, $limit) => $api->getAlbumTracks($spotifyAlbumId, ['limit' => $limit, 'offset' => $offset])) as $item) {
            if (empty($item['id'])) {
                continue;
            }

            // Album tracks come without their album.
            $track = $this->importTrackItem($item + ['album' => $albumInfo], $into);
            $album ??= $track->album;
            $count++;
        }

        if ($album !== null && !empty($spotifyAlbum['uri'])) {
            Metadata::updateOrCreate(
                ['metadatable_type' => $album->getMorphClass(), 'metadatable_id' => $album->id, 'key' => 'spotify_album_uri'],
                ['value' => $spotifyAlbum['uri'], 'type' => 'string', 'source' => 'spotify'],
            );
        }

        return $count;
    }

    /** The Spotify id of a library album: stored URI, a Spotify track of it, else a search by name. */
    public function findAlbumId(Album $album): ?string
    {
        $stored = $album->metadata()->where('key', 'spotify_album_uri')->value('value');
        if (is_string($stored) && str_starts_with($stored, 'spotify:album:')) {
            return substr($stored, strlen('spotify:album:'));
        }

        $api = $this->tokenService->makeApiClient();

        $uri = $album->tracks()->where('source', 'spotify')->where('external_id', 'like', 'spotify:track:%')->value('external_id');
        if ($uri) {
            $id = $api->getTrack(substr($uri, strlen('spotify:track:')))['album']['id'] ?? null;
            if ($id) {
                return $id;
            }
        }

        $artist = $album->artist;
        $results = $api->search('album:'.$album->name.' artist:'.$artist->name, 'album', ['limit' => 10]);

        foreach ($results['albums']['items'] ?? [] as $candidate) {
            $sameAlbum = Normalizer::album($candidate['name'] ?? null) === Normalizer::album($album->name);
            $sameArtist = collect($candidate['artists'] ?? [])->contains(fn ($a) => Normalizer::artist($a['name'] ?? null) === Normalizer::artist($artist->name));

            if ($sameAlbum && $sameArtist) {
                return $candidate['id'];
            }
        }

        return null;
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

    /** Imports one Spotify track (and its artist and album record) without the rest of its album. */
    public function importTrackById(string $spotifyTrackId): Track
    {
        return $this->importTrackItem($this->tokenService->makeApiClient()->getTrack($spotifyTrackId));
    }

    /** @param  Album|null  $into  put the track on this album (and artist) instead of looking them up by name */
    private function importTrackItem(array $spotifyTrack, ?Album $into = null): Track
    {
        $artistName = $spotifyTrack['artists'][0]['name'] ?? 'Unknown Artist';
        $albumName = $spotifyTrack['album']['name'] ?? 'Unknown Album';
        $images = $spotifyTrack['album']['images'] ?? [];

        // One record per artist/album/track whatever the source (LibraryIdentity).
        $artist = $into?->artist ?? LibraryIdentity::artist($artistName, 'spotify');

        $album = $into ?? LibraryIdentity::album($artist, $albumName, 'spotify', [
            'images' => $images,
            'released_at' => $spotifyTrack['album']['release_date'] ?? null,
        ]);

        $this->maybeProcessArtwork($images[0]['url'] ?? null);

        $track = LibraryIdentity::track(
            $artist,
            $album,
            $spotifyTrack['name'] ?: 'Unknown Track',
            'spotify:track:'.$spotifyTrack['id'],
            'spotify',
            [
                'duration' => isset($spotifyTrack['duration_ms']) ? (int) round($spotifyTrack['duration_ms'] / 1000) : null,
                'images' => $images,
            ],
        );

        if ($track->wasRecentlyCreated) {
            Enrichment::queue($track);
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
