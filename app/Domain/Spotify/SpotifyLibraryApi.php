<?php

namespace App\Domain\Spotify;

use App\Domain\Library\SpotifyUri;
use App\Services\SpotifyTokenService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SpotifyWebAPI\SpotifyWebAPIException;

/**
 * The Web API calls Spotify changed in February 2026, which the jwilsson SDK still sends the old way:
 * saving/removing/checking tracks (the per-type /me/tracks endpoints became the generic /me/library
 * ones that take Spotify URIs) and a playlist's entries (/playlists/{id}/tracks became /items).
 *
 * Each call tries the new endpoint first and, only when Spotify answers 404/405/410 (not there for
 * this app), falls back to the old one, so both an app on the new rules and one still on the old
 * ones keep working. See docs/architecture/spotify-api-audit.md.
 */
class SpotifyLibraryApi
{
    /** Statuses meaning "this endpoint does not exist (for this app)", as opposed to a failed call. */
    private const MISSING = [404, 405, 410];

    public function __construct(private SpotifyTokenService $tokens) {}

    /** Save (or remove) a track in Liked Songs. @throws SpotifyWebAPIException */
    public function setTrackSaved(string $trackId, bool $saved): void
    {
        $uri = SpotifyUri::track($trackId);
        $method = $saved ? 'PUT' : 'DELETE';

        $response = $this->request($method, '/me/library', ['uris' => $uri]);

        if (in_array($response->status(), self::MISSING, true)) {
            $response = $this->request($method, '/me/tracks', ['ids' => $trackId]);
        }

        $this->check($response);
    }

    /** Whether a track is in Liked Songs. @throws SpotifyWebAPIException */
    public function isTrackSaved(string $trackId): bool
    {
        $response = $this->request('GET', '/me/library/contains', ['uris' => SpotifyUri::track($trackId)]);

        if (in_array($response->status(), self::MISSING, true)) {
            $response = $this->request('GET', '/me/tracks/contains', ['ids' => $trackId]);
        }

        $this->check($response);

        return (bool) ($response->json()[0] ?? false);
    }

    /**
     * One page of a playlist's entries `{ items, total }`, each entry `{ track: {...} }`; null when
     * Spotify won't list them (not the owner's or a collaborative playlist: 403/404).
     *
     * @return array{items: list<array>, total: int}|null
     *
     * @throws SpotifyWebAPIException for failures other than "not available"
     */
    public function playlistEntries(string $playlistId, int $offset, int $limit): ?array
    {
        $query = ['limit' => $limit, 'offset' => $offset, 'additional_types' => 'track'];
        $response = $this->request('GET', "/playlists/{$playlistId}/items", $query);

        if (in_array($response->status(), self::MISSING, true)) {
            $response = $this->request('GET', "/playlists/{$playlistId}/tracks", $query);
        }

        if (in_array($response->status(), [...self::MISSING, 403], true)) {
            return null;
        }

        $this->check($response);

        $items = array_map(function ($entry) {
            // The items endpoint may name the track `item`.
            if (!isset($entry['track']) && isset($entry['item'])) {
                $entry['track'] = $entry['item'];
            }

            return $entry;
        }, (array) $response->json('items', []));

        return ['items' => $items, 'total' => (int) $response->json('total', 0)];
    }

    private function request(string $method, string $path, array $query): Response
    {
        $http = Http::withToken((string) $this->tokens->getAccessToken())->acceptJson()->timeout(15);
        $url = rtrim((string) config('spotify.api_url'), '/').$path;

        return $method === 'GET' ? $http->get($url, $query) : $http->send($method, $url.'?'.http_build_query($query));
    }

    private function check(Response $response): void
    {
        if ($response->failed()) {
            throw new SpotifyWebAPIException((string) $response->json('error.message', 'Spotify request failed'), $response->status());
        }
    }
}
