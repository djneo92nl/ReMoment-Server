<?php

namespace App\Domain\Availability;

use App\Domain\Library\LibraryIdentity;
use App\Domain\Library\Normalizer;
use App\Domain\Library\SpotifyCatalog;
use App\Domain\Library\SpotifyImages;
use App\Domain\Library\SpotifyUri;
use App\Domain\Spotify\SpotifyLibraryApi;
use App\Services\Lastfm\LastfmInfoClient;
use App\Services\SpotifyTokenService;
use Illuminate\Support\Facades\Cache;

/** Spotify as an external catalogue: the existing SpotifyCatalog reads, nothing is imported. */
final class SpotifyBrowser implements BrowsesExternalCatalogue
{
    private const TTL = 300;

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
        $tracks = array_map(fn (array $t) => $this->item('track', $t + ['artists' => $info['artists'] ?? [], 'album' => ['name' => $info['name'], 'images' => $info['images'] ?? []]]), $this->catalog->tracksOfSpotifyAlbum($id));

        return ['album' => $album, 'tracks' => $tracks];
    }

    public function playlists(): array
    {
        $raw = Cache::remember('spotify-browse:playlists', self::TTL, function () {
            $api = $this->spotify->makeApiClient();
            $all = [];
            for ($page = 0; $page < 4; $page++) {
                $response = $api->getMyPlaylists(['limit' => 50, 'offset' => $page * 50]);
                foreach ($response['items'] ?? [] as $playlist) {
                    if (!empty($playlist['id'])) {
                        $all[] = $playlist;
                    }
                }
                if (empty($response['next'])) {
                    break;
                }
            }

            return $all;
        });

        return array_map(fn (array $p) => $this->playlistItem($p), $raw);
    }

    public function account(string $section, int $limit = 50): array
    {
        $limit = max(1, min(50, $limit));

        // Short cache: the Streaming page is opened often, and a liked song should show soon.
        $raw = Cache::remember('spotify-browse:account:'.$section.':'.$limit, 120, function () use ($section, $limit) {
            $api = $this->spotify->makeApiClient();

            return match ($section) {
                'saved-albums' => array_values(array_filter(array_column($api->getMySavedAlbums(['limit' => $limit])['items'] ?? [], 'album'))),
                'liked-tracks' => array_values(array_filter(array_column($api->getMySavedTracks(['limit' => $limit])['items'] ?? [], 'track'))),
                'recent' => array_values(array_filter(array_map(fn ($i) => isset($i['track']) ? $i['track'] + ['played_at' => $i['played_at'] ?? null] : null, $api->getMyRecentTracks(['limit' => $limit])['items'] ?? []))),
                default => [],
            };
        });

        $type = $section === 'saved-albums' ? 'album' : 'track';
        $items = [];
        foreach ($raw as $data) {
            // A track played twice in a row (or an episode) is listed once; only tracks and albums with an id.
            if (empty($data['id']) || isset($items[$data['id']])) {
                continue;
            }
            $items[$data['id']] = $this->item($type, $data);
        }

        return array_values($items);
    }

    public function playlist(string $id): ?array
    {
        $info = Cache::remember('spotify-browse:playlist:'.$id, self::TTL, fn () => $this->spotify->makeApiClient()->getPlaylist($id, ['fields' => 'id,name,owner(display_name),images,tracks(total)']));
        if (empty($info['id'])) {
            return null;
        }

        $tracks = Cache::remember('spotify-browse:playlist-tracks:'.$id, self::TTL, function () use ($id) {
            $api = app(SpotifyLibraryApi::class);
            $items = [];
            for ($offset = 0; $offset < 500; $offset += 100) {
                $page = $api->playlistEntries($id, $offset, 100);
                if ($page === null) {
                    return $offset === 0 ? false : $items;
                }
                foreach ($page['items'] as $entry) {
                    $track = $entry['track'] ?? null;
                    if (is_array($track) && !empty($track['id']) && ($track['type'] ?? 'track') === 'track') {
                        $items[] = $track;
                    }
                }
                if (count($page['items']) < 100 || $offset + 100 >= $page['total']) {
                    break;
                }
            }

            return $items;
        });

        return [
            'playlist' => $this->playlistItem($info),
            'tracks' => $tracks === false ? null : array_map(fn (array $t) => $this->item('track', $t), $tracks),
        ];
    }

    /** @param  array<string, mixed>  $data  a Spotify playlist object */
    private function playlistItem(array $data): ExternalItem
    {
        return new ExternalItem(
            provider: 'spotify',
            type: 'playlist',
            id: (string) $data['id'],
            title: (string) ($data['name'] ?? ''),
            artist: $data['owner']['display_name'] ?? null,
            trackCount: isset($data['tracks']['total']) ? (int) $data['tracks']['total'] : (isset($data['items']['total']) ? (int) $data['items']['total'] : null),
            image: SpotifyImages::pick($data['images'] ?? [], 200),
        );
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
            playedAt: $data['played_at'] ?? null,
            libraryId: match ($type) {
                'artist' => SpotifyCatalog::localArtist((string) ($data['name'] ?? ''))?->id,
                'album' => SpotifyCatalog::localAlbum((string) ($data['name'] ?? ''), $artistName)?->id,
                // The library's own copy: by Spotify id when it carries one, else by artist, album and title (local tracks have no Spotify id).
                default => $artistName !== null && ($data['name'] ?? '') !== ''
                    ? LibraryIdentity::lookupTrack($artistName, $data['album']['name'] ?? null, (string) $data['name'], SpotifyUri::track((string) $data['id']), 'spotify', isset($data['duration_ms']) ? intdiv((int) $data['duration_ms'], 1000) : (isset($data['duration']) ? (int) $data['duration'] : null))?->id
                    : null,
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
