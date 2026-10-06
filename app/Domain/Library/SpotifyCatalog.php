<?php

namespace App\Domain\Library;

use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Services\SpotifyTokenService;
use Illuminate\Support\Facades\Cache;

/**
 * What Spotify has beyond the local library, for the "More on Spotify" parts of the UI:
 * search results, an artist's albums and an album's tracks, reduced to plain arrays and
 * cached for an hour. Nothing is written here; adding goes through ImportSpotifyAlbumById.
 */
class SpotifyCatalog
{
    private const TTL = 3600;

    private const MAX_PAGES = 4;

    public function __construct(
        private SpotifyTokenService $spotify,
        private SpotifyLibraryImporter $importer,
    ) {}

    public function available(): bool
    {
        return $this->spotify->isConnected();
    }

    /** @return array{artists: list<array>, albums: list<array>} */
    public function search(string $term): array
    {
        return Cache::remember('spotify-catalog:search:'.md5(mb_strtolower($term)), self::TTL, function () use ($term) {
            $results = $this->spotify->makeApiClient()->search($term, ['artist', 'album'], ['limit' => 5]);

            return [
                'artists' => array_map(fn ($a) => [
                    'id' => $a['id'],
                    'name' => $a['name'],
                    'image' => $this->image($a['images'] ?? []),
                ], $results['artists']['items'] ?? []),
                'albums' => array_map(fn ($a) => $this->albumItem($a), $results['albums']['items'] ?? []),
            ];
        });
    }

    /**
     * An artist's albums and singles on Spotify (one entry per normalized name), newest first.
     *
     * @return list<array{id: string, name: string, artist: ?string, year: ?string, image: ?string}>
     */
    public function artistAlbums(Artist $artist): array
    {
        $spotifyId = $artist->metadata()->where('key', 'spotify_id')->value('value') ?: $this->findArtistId($artist);

        return $spotifyId ? $this->albumsOfSpotifyArtist($spotifyId) : [];
    }

    /** @return list<array{id: string, name: string, artist: ?string, year: ?string, image: ?string}> */
    public function albumsOfSpotifyArtist(string $spotifyArtistId): array
    {
        return Cache::remember('spotify-catalog:artist-albums:'.$spotifyArtistId, self::TTL, function () use ($spotifyArtistId) {
            $api = $this->spotify->makeApiClient();
            $albums = [];

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $response = $api->getArtistAlbums($spotifyArtistId, ['include_groups' => 'album,single', 'limit' => 50, 'offset' => $page * 50]);

                foreach ($response['items'] ?? [] as $item) {
                    $albums[Normalizer::album($item['name'] ?? null)] ??= $this->albumItem($item);
                }

                if (empty($response['next'])) {
                    break;
                }
            }

            $albums = array_values($albums);
            usort($albums, fn ($a, $b) => strcmp($b['year'] ?? '', $a['year'] ?? ''));

            return $albums;
        });
    }

    /**
     * The tracks of an album's Spotify release, null when it isn't found there.
     *
     * @return list<array{id: string, name: string, number: int, duration: int}>|null
     */
    public function albumTracks(Album $album): ?array
    {
        $spotifyAlbumId = $this->importer->findAlbumId($album);

        if ($spotifyAlbumId === null) {
            return null;
        }

        return Cache::remember('spotify-catalog:album-tracks:'.$spotifyAlbumId, self::TTL, function () use ($spotifyAlbumId) {
            $api = $this->spotify->makeApiClient();
            $tracks = [];

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $response = $api->getAlbumTracks($spotifyAlbumId, ['limit' => 50, 'offset' => $page * 50]);

                foreach ($response['items'] ?? [] as $item) {
                    $tracks[] = [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'number' => (int) ($item['track_number'] ?? 0),
                        'duration' => intdiv((int) ($item['duration_ms'] ?? 0), 1000),
                    ];
                }

                if (empty($response['next'])) {
                    break;
                }
            }

            return $tracks;
        });
    }

    /** Library album and artist matching a Spotify album, if any. */
    public static function localAlbum(string $name, ?string $artistName): ?Album
    {
        return Album::query()
            ->where('name_key', Normalizer::album($name))
            ->whereHas('artist', fn ($q) => $q->where('name_key', Normalizer::artist($artistName)))
            ->orderBy('id')
            ->first();
    }

    public static function localArtist(string $name): ?Artist
    {
        return Artist::query()->where('name_key', Normalizer::artist($name))->orderBy('id')->first();
    }

    private function findArtistId(Artist $artist): ?string
    {
        $results = $this->spotify->makeApiClient()->search('artist:'.$artist->name, 'artist', ['limit' => 10]);

        foreach ($results['artists']['items'] ?? [] as $candidate) {
            if (Normalizer::artist($candidate['name'] ?? null) === Normalizer::artist($artist->name) && !empty($candidate['id'])) {
                return $candidate['id'];
            }
        }

        return null;
    }

    private function albumItem(array $album): array
    {
        return [
            'id' => $album['id'],
            'name' => $album['name'],
            'artist' => $album['artists'][0]['name'] ?? null,
            'year' => isset($album['release_date']) ? substr($album['release_date'], 0, 4) : null,
            'image' => $this->image($album['images'] ?? []),
        ];
    }

    /** A medium-sized image: the smallest one at least 200px wide, else the largest. */
    private function image(array $images): ?string
    {
        usort($images, fn ($a, $b) => ($a['width'] ?? 0) <=> ($b['width'] ?? 0));

        foreach ($images as $image) {
            if (($image['width'] ?? 0) >= 200) {
                return $image['url'];
            }
        }

        return $images ? (end($images)['url'] ?? null) : null;
    }
}
