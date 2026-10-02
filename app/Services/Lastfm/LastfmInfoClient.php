<?php

namespace App\Services\Lastfm;

use Illuminate\Support\Facades\Http;

/** Read-only Last.fm calls (`*.getInfo`), which need only the API key. */
class LastfmInfoClient
{
    /** Last.fm error codes worth retrying: operation failed, offline, temporarily unavailable, rate limit. */
    private const RETRY_ERRORS = [8, 11, 16, 29];

    public static function enabled(): bool
    {
        return (bool) config('lastfm.api_key');
    }

    /**
     * The decoded response, or null when Last.fm has nothing for the request (unknown artist, track or album).
     * A rate limit or outage throws, so the calling job is retried.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $method, array $params): ?array
    {
        $response = Http::withHeaders(['User-Agent' => 'ReMoment/1.0 (remko@pionect.nl)'])
            ->get('https://ws.audioscrobbler.com/2.0/', $params + [
                'method' => $method,
                'api_key' => config('lastfm.api_key'),
                'format' => 'json',
                'autocorrect' => 1,
            ]);

        if ($response->status() === 429 || $response->serverError()) {
            $response->throw();
        }

        $data = $response->json();

        if (is_array($data) && isset($data['error'])) {
            if (in_array($data['error'], self::RETRY_ERRORS, true)) {
                throw new \RuntimeException("Last.fm error {$data['error']}: ".($data['message'] ?? ''));
            }

            return null;
        }

        return $response->ok() && is_array($data) ? $data : null;
    }

    /** A Last.fm wiki or bio summary without its trailing "Read more on Last.fm" link. */
    public static function cleanSummary(?string $summary): ?string
    {
        $text = trim(preg_replace('/\s*<a href="[^"]*">Read more on Last\.fm<\/a>\.?\s*$/', '', (string) $summary));

        return $text !== '' ? $text : null;
    }

    /** @return list<string> tag names of a `tags.tag` / `toptags.tag` list, most popular first */
    public static function tagNames(?array $tags): array
    {
        // Last.fm returns a lone tag as an object instead of a list.
        if (isset($tags['name'])) {
            $tags = [$tags];
        }

        return array_values(array_filter(array_column($tags ?? [], 'name')));
    }
}
