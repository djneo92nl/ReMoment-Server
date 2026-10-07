<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The HTTP calls of the metadata services (MusicBrainz, Discogs, TheAudioDB, Last.fm, Wikipedia, LRCLIB,
 * Cover Art Archive): one User-Agent, and one retry policy. A rate limit (429) or an outage (5xx) throws,
 * so the calling queued job is retried; every other answer is returned for the caller to read.
 */
final class MetadataHttp
{
    /** @param  array<string, string>  $headers */
    public static function request(array $headers = []): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => config('metadata.user_agent')] + $headers);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    public static function get(string $url, array $query = [], array $headers = []): Response
    {
        $response = self::request($headers)->get($url, $query);

        if ($response->status() === 429 || $response->serverError()) {
            $response->throw();
        }

        return $response;
    }
}
