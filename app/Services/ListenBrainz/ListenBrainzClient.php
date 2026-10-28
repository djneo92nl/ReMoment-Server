<?php

namespace App\Services\ListenBrainz;

use App\Services\MetadataHttp;

/**
 * ListenBrainz (https://listenbrainz.readthedocs.io/en/latest/users/api/index.html): about one request a second, an identifying
 * User-Agent (MetadataHttp sends ours). Only the global fresh-releases feed is used; whether it needs a token is not stated in
 * the docs, so none is sent. A rate limit or outage throws, so the queued job is retried.
 */
class ListenBrainzClient
{
    private const BASE = 'https://api.listenbrainz.org/1';

    /**
     * Releases around today, past and upcoming (at most 90 days): one entry per release-group with its artists' MusicBrainz
     * ids. The response is read as a plain list, or as `{ payload: { releases: [...] } }` (the shape is not pinned in the docs).
     *
     * @return list<array{release_group_mbid: string, artist_mbids: list<string>, title: string, type: ?string, date: ?string, artist: ?string}>
     */
    public function freshReleases(int $days = 90): array
    {
        $response = MetadataHttp::get(self::BASE.'/explore/fresh-releases/', ['days' => max(1, min(90, $days)), 'past' => 'true', 'future' => 'true', 'sort' => 'release_date'], ['Accept' => 'application/json']);
        if (!$response->ok()) {
            return [];
        }

        $json = $response->json();
        $rows = is_array($json) && array_is_list($json) ? $json : ($json['payload']['releases'] ?? $json['releases'] ?? []);

        $releases = [];
        foreach ($rows as $row) {
            if (empty($row['release_group_mbid']) || empty($row['artist_mbids'])) {
                continue;
            }
            $releases[] = [
                'release_group_mbid' => (string) $row['release_group_mbid'],
                'artist_mbids' => array_values(array_map('strval', (array) $row['artist_mbids'])),
                'title' => (string) ($row['release_name'] ?? ''),
                'type' => isset($row['release_group_primary_type']) ? strtolower((string) $row['release_group_primary_type']) : null,
                'date' => $row['release_date'] ?? null,
                'artist' => $row['artist_credit_name'] ?? null,
            ];
        }

        return $releases;
    }
}
