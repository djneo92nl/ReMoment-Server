<?php

namespace App\Services\ListenBrainz;

use App\Services\MetadataHttp;

/**
 * ListenBrainz (https://listenbrainz.readthedocs.io/en/latest/users/api/index.html): an identifying User-Agent (MetadataHttp sends
 * ours). Only the global fresh-releases feed is used. Checked live 2026-10-30: it works without a token, and answers carry
 * `X-RateLimit-Limit: 30` per window (`X-RateLimit-Reset-In`); we make one call a day, spaced by the `listenbrainz` limiter.
 * A rate limit or outage throws, so the queued job is retried.
 */
class ListenBrainzClient
{
    private const BASE = 'https://api.listenbrainz.org/1';

    /**
     * Releases around today, past and upcoming (at most 90 days): one entry per release-group with its artists' MusicBrainz
     * ids. The real body is `{ payload: { releases: [...], total_count } }` (the docs say a plain list; a list is still read).
     * It is big: about 2 MB for 14 days (4,700 releases, most of them singles) and 14 MB for 90 days (33,000), about 75 MB of
     * PHP arrays, so read the shortest window you need. `release_group_primary_type` is missing on about 1% of the rows;
     * `release_group_secondary_type` (one value: Live, Compilation, Soundtrack, Remix ...) is present only when the group has one.
     *
     * @return list<array{release_group_mbid: string, artist_mbids: list<string>, title: string, type: ?string, secondary_type: ?string, date: ?string, artist: ?string}>
     */
    public function freshReleases(int $days = 30): array
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
                'secondary_type' => isset($row['release_group_secondary_type']) ? strtolower((string) $row['release_group_secondary_type']) : null,
                'date' => $row['release_date'] ?? null,
                'artist' => $row['artist_credit_name'] ?? null,
            ];
        }

        return $releases;
    }
}
