<?php

namespace App\Services\Discogs;

use App\Domain\Credentials\ServiceCredentials;
use App\Services\MetadataHttp;

class DiscogsClient
{
    public static function enabled(): bool
    {
        return ServiceCredentials::isSet('discogs', 'token');
    }

    /**
     * A decoded Discogs API response (e.g. get('database/search', [...]), get('releases/123')), or null when
     * there is nothing. A rate limit or outage throws, so the calling job is retried.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $path, array $query = []): ?array
    {
        $response = MetadataHttp::get(ServiceCredentials::baseUrl('discogs').'/'.$path, $query, [
            'Authorization' => 'Discogs token='.ServiceCredentials::value('discogs', 'token'),
        ]);

        return $response->ok() ? $response->json() : null;
    }
}
