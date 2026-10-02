<?php

namespace App\Services\AudioDb;

use Illuminate\Support\Facades\Http;

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
        $response = Http::withHeaders(['User-Agent' => 'ReMoment/1.0 (remko@pionect.nl)'])
            ->get('https://www.theaudiodb.com/api/v1/json/'.config('metadata.audiodb_key')."/{$endpoint}", ['i' => $mbid]);

        if ($response->status() === 429 || $response->serverError()) {
            $response->throw();
        }

        return $response->ok() ? $response->json() : null;
    }
}
