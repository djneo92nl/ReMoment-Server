<?php

namespace App\Services\Discogs;

use App\Services\MetadataHttp;

class DiscogsClient
{
    public static function enabled(): bool
    {
        return (bool) config('metadata.discogs_token');
    }

    /**
     * A decoded Discogs API response (e.g. get('database/search', [...]), get('releases/123')), or null when
     * there is nothing. A rate limit or outage throws, so the calling job is retried.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $path, array $query = []): ?array
    {
        $response = MetadataHttp::get("https://api.discogs.com/{$path}", $query, [
            'Authorization' => 'Discogs token='.config('metadata.discogs_token'),
        ]);

        return $response->ok() ? $response->json() : null;
    }
}
