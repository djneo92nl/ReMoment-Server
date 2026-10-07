<?php

namespace App\Services\MusicBrainz;

use App\Services\MetadataHttp;
use Illuminate\Support\Sleep;

/**
 * MusicBrainz web service, one instance per job: calls made by the same instance are a second apart
 * (the service allows about one request per second; the `musicbrainz` rate limiter spaces the jobs).
 */
class MusicBrainzClient
{
    /** Link relation types (and hosts) mapped to the keys of the `links` metadata. */
    private const LINK_TYPES = [
        'official homepage' => 'official',
        'wikidata' => 'wikidata',
        'wikipedia' => 'wikipedia',
        'discogs' => 'discogs',
        'allmusic' => 'allmusic',
        'last.fm' => 'lastfm',
        'bandcamp' => 'bandcamp',
        'songkick' => 'songkick',
        'youtube' => 'youtube',
    ];

    private const LINK_HOSTS = [
        'twitter.com' => 'twitter',
        'x.com' => 'twitter',
        'instagram.com' => 'instagram',
        'facebook.com' => 'facebook',
        'open.spotify.com' => 'spotify',
        'music.apple.com' => 'apple_music',
        'deezer.com' => 'deezer',
        'tidal.com' => 'tidal',
        'youtube.com' => 'youtube',
    ];

    private bool $requested = false;

    /**
     * A decoded response, or null when there is nothing (404, bad query). A rate limit or outage
     * throws, so the calling job is retried.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $path, array $query = []): ?array
    {
        if ($this->requested) {
            Sleep::for(1)->second();
        }
        $this->requested = true;

        $response = MetadataHttp::get("https://musicbrainz.org/ws/2/{$path}", $query + ['fmt' => 'json'], ['Accept' => 'application/json']);

        return $response->ok() ? $response->json() : null;
    }

    /**
     * The URL relations of an artist or release group as a map (official, wikidata, wikipedia, discogs,
     * bandcamp, lastfm, spotify, twitter, ...); the first URL of each kind wins.
     *
     * @param  array<int, array<string, mixed>>  $relations
     * @return array<string, string>
     */
    public static function links(array $relations): array
    {
        $links = [];

        foreach ($relations as $relation) {
            $url = $relation['url']['resource'] ?? null;
            if (!$url) {
                continue;
            }

            $host = preg_replace('/^www\./', '', strtolower(parse_url($url, PHP_URL_HOST) ?: ''));
            $key = self::LINK_TYPES[$relation['type'] ?? ''] ?? self::LINK_HOSTS[$host] ?? null;

            if ($key !== null) {
                $links[$key] ??= $url;
            }
        }

        return $links;
    }

    /** The Wikidata id (Q…) in a `links` map, if any. */
    public static function wikidataId(array $links): ?string
    {
        return isset($links['wikidata']) && preg_match('~/(Q\d+)$~', $links['wikidata'], $m) ? $m[1] : null;
    }
}
