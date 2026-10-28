<?php

namespace App\Domain\Availability;

use App\Domain\Library\LibraryIdentity;
use App\Domain\Library\Normalizer;
use App\Domain\Library\SpotifyCatalog;
use App\Domain\Library\SpotifyImages;
use App\Domain\Library\SpotifyUri;
use App\Jobs\ImportSpotifyAlbumById;
use App\Jobs\ImportSpotifyTrackById;
use App\Services\Lastfm\LastfmInfoClient;
use App\Services\SpotifyTokenService;
use Illuminate\Support\Facades\Cache;

/** Spotify as an external catalogue: the existing SpotifyCatalog reads, the existing import jobs for adding. */
final class SpotifyBrowser implements BrowsesExternalCatalogue
{
    private const TTL = 300;

    /** Albums one "add artist" queues at most, so a huge discography cannot flood the queue. */
    public const MAX_ARTIST_ALBUMS = 100;

    public function __construct(private readonly SpotifyCatalog $catalog, private readonly SpotifyTokenService $spotify) {}

    public function id(): string
    {
        return 'spotify';
    }

    public function enabled(): bool
    {
        return $this->catalog->available();
    }

    public function search(string $term, string $type, int $limit = 10): array
    {
        // Search answers at most 10 items since February 2026 (docs/architecture/spotify-api-audit.md).
        $limit = max(1, min(10, $limit));

        $response = Cache::remember('spotify-browse:search:'.$type.':'.$limit.':'.md5(mb_strtolower($term)), self::TTL,
            fn () => $this->spotify->makeApiClient()->search($term, $type, ['limit' => $limit]));

        return array_map(fn (array $item) => $this->item($type, $item), $response[$type.'s']['items'] ?? []);
    }

    public function artist(string $id): ?array
    {
        $info = Cache::remember('spotify-browse:artist:'.$id, self::TTL, fn () => $this->spotify->makeApiClient()->getArtist($id));
        if (empty($info['id'])) {
            return null;
        }

        $albums = array_map(fn (array $a) => $this->albumItem($a), $this->catalog->albumsOfSpotifyArtist($id));

        return ['artist' => $this->item('artist', $info), 'albums' => $albums];
    }

    public function album(string $id): ?array
    {
        $info = Cache::remember('spotify-browse:album:'.$id, self::TTL, fn () => $this->spotify->makeApiClient()->getAlbum($id));
        if (empty($info['id'])) {
            return null;
        }

        $album = $this->item('album', $info);
        $tracks = array_map(fn (array $t) => $this->item('track', $t + ['album' => ['name' => $info['name'], 'images' => $info['images'] ?? []]]), $this->catalog->tracksOfSpotifyAlbum($id));

        return ['album' => $album, 'tracks' => $tracks];
    }

    /**
     * Spotify's own top-tracks endpoint is gone (February 2026), so: Last.fm's top tracks for the artist when a Last.fm key is set,
     * each found on Spotify by name (one search each, cached), else one Spotify search for the artist's tracks. Only tracks of this
     * Spotify artist count. Cached for six hours.
     */
    public function popularTracks(string $artistId, string $artistName, int $limit = 10): array
    {
        $raw = Cache::remember('spotify-browse:popular:'.$artistId.':'.$limit, 21600, fn () => $this->findPopular($artistId, $artistName, $limit));

        return array_map(fn (array $t) => $this->item('track', $t), $raw);
    }

    /** @return list<array<string, mixed>> raw Spotify track objects */
    private function findPopular(string $artistId, string $artistName, int $limit): array
    {
        $api = $this->spotify->makeApiClient();
        $byArtist = fn (array $t) => collect($t['artists'] ?? [])->contains(fn ($a) => ($a['id'] ?? null) === $artistId);
        $found = [];

        if (LastfmInfoClient::enabled()) {
            try {
                $names = array_column(app(LastfmInfoClient::class)->get('artist.gettoptracks', ['artist' => $artistName, 'limit' => $limit + 5])['toptracks']['track'] ?? [], 'name');
            } catch (\Throwable) {
                $names = [];
            }

            foreach ($names as $name) {
                if (count($found) >= $limit) {
                    break;
                }
                $hits = $api->search('track:"'.str_replace('"', '', (string) $name).'" artist:"'.str_replace('"', '', $artistName).'"', 'track', ['limit' => 5])['tracks']['items'] ?? [];
                foreach ($hits as $hit) {
                    if ($byArtist($hit) && Normalizer::track($hit['name'] ?? null) === Normalizer::track((string) $name) && !isset($found[$hit['id']])) {
                        $found[$hit['id']] = $hit;
                        break;
                    }
                }
            }
        }

        if ($found === []) {
            foreach ($api->search('artist:"'.str_replace('"', '', $artistName).'"', 'track', ['limit' => 10])['tracks']['items'] ?? [] as $hit) {
                if ($byArtist($hit) && count($found) < $limit) {
                    $found[$hit['id']] = $hit;
                }
            }
        }

        return array_values($found);
    }

    public function add(string $type, string $id, ?array $albumIds = null): array
    {
        if ($type === 'track') {
            ImportSpotifyTrackById::dispatch($id);

            return ['albums' => 0, 'tracks' => 1];
        }

        if ($type === 'album') {
            ImportSpotifyAlbumById::dispatch($id);

            return ['albums' => 1, 'tracks' => 0];
        }

        $wanted = $albumIds;
        if ($wanted === null) {
            $wanted = [];
            foreach ($this->catalog->albumsOfSpotifyArtist($id) as $album) {
                // Albums the library already has are not asked for again.
                if (SpotifyCatalog::localAlbum($album['name'], $album['artist']) === null) {
                    $wanted[] = $album['id'];
                }
            }
        }

        $wanted = array_slice(array_values(array_unique($wanted)), 0, self::MAX_ARTIST_ALBUMS);
        foreach ($wanted as $albumId) {
            ImportSpotifyAlbumById::dispatch($albumId);
        }

        return ['albums' => count($wanted), 'tracks' => 0];
    }

    /** @param  array<string, mixed>  $data  a Spotify artist, album or track object */
    private function item(string $type, array $data): ExternalItem
    {
        $artistName = $data['artists'][0]['name'] ?? null;
        $images = $data['images'] ?? $data['album']['images'] ?? [];

        return new ExternalItem(
            provider: 'spotify',
            type: $type,
            id: (string) $data['id'],
            title: (string) ($data['name'] ?? ''),
            artist: $type === 'artist' ? null : $artistName,
            year: isset($data['release_date']) ? substr($data['release_date'], 0, 4) : (isset($data['album']['release_date']) ? substr($data['album']['release_date'], 0, 4) : null),
            trackCount: isset($data['total_tracks']) ? (int) $data['total_tracks'] : null,
            image: SpotifyImages::pick($images, 200),
            libraryId: match ($type) {
                'artist' => SpotifyCatalog::localArtist((string) ($data['name'] ?? ''))?->id,
                'album' => SpotifyCatalog::localAlbum((string) ($data['name'] ?? ''), $artistName)?->id,
                default => LibraryIdentity::findByExternalId(SpotifyUri::track((string) $data['id']), 'spotify')?->id,
            },
        );
    }

    /** @param  array{id: string, name: string, artist: ?string, year: ?string, image: ?string}  $album  a SpotifyCatalog album entry */
    private function albumItem(array $album): ExternalItem
    {
        return new ExternalItem('spotify', 'album', $album['id'], $album['name'], $album['artist'], $album['year'], null, $album['image'],
            SpotifyCatalog::localAlbum($album['name'], $album['artist'])?->id);
    }
}
