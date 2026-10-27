<?php

namespace App\Services\AudioDb;

use App\Domain\Credentials\ServiceCredentials;
use App\Services\MetadataHttp;

class AudioDbClient
{
    /**
     * A TheAudioDB lookup by MusicBrainz id, e.g. get('artist-mb.php', $mbid); null when it has nothing
     * (it answers with null lists). A rate limit or outage throws, so the calling job is retried.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $endpoint, string $mbid): ?array
    {
        $response = MetadataHttp::get(ServiceCredentials::baseUrl('audiodb').'/'.ServiceCredentials::value('audiodb', 'api_key')."/{$endpoint}", ['i' => $mbid]);

        return $response->ok() ? $response->json() : null;
    }
}
