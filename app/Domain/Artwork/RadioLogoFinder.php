<?php

namespace App\Domain\Artwork;

use Illuminate\Support\Facades\Http;

/**
 * Finds a station's logo on Radio Browser (radio-browser.info) by name, for stations saved
 * without an image_url. Only returns a logo ProcessArtwork can decode.
 */
final class RadioLogoFinder
{
    private const ENDPOINT = 'https://de1.api.radio-browser.info/json/stations/byname/';

    /** Search spellings, most specific first: "KINK 80s" is listed as "KINK80s", "181.FM Energy 98" as "181.FM - Energy 98". */
    public static function find(string $name): ?string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $queries = array_unique([trim($name), implode('', $words), implode(' ', array_slice($words, -2))]);

        foreach ($queries as $query) {
            $logo = self::search($query, $name);

            if ($logo !== null) {
                return $logo;
            }
        }

        return null;
    }

    /** The favicon of the best-voted station whose name equals $name once case, spaces and punctuation are ignored. */
    private static function search(string $query, string $name): ?string
    {
        $response = Http::timeout(5)->acceptJson()->get(self::ENDPOINT.rawurlencode($query), [
            'hidebroken' => 'true',
            'order' => 'votes',
            'reverse' => 'true',
            'limit' => 50,
        ]);

        if (!$response->successful()) {
            return null;
        }

        $tried = [];

        foreach ($response->json() ?? [] as $candidate) {
            $favicon = trim((string) ($candidate['favicon'] ?? ''));

            if (self::key((string) ($candidate['name'] ?? '')) === self::key($name)
                && str_starts_with($favicon, 'http')
                && filter_var($favicon, FILTER_VALIDATE_URL)
                && !isset($tried[$favicon])) {
                $tried[$favicon] = true;

                if (self::isUsableImage($favicon)) {
                    return $favicon;
                }
            }
        }

        return null;
    }

    /** ProcessArtwork must be able to download and decode it: a JPEG, PNG, GIF or WebP (not an .ico or an HTML error page). */
    private static function isUsableImage(string $url): bool
    {
        try {
            $response = Http::timeout(5)->withUserAgent('ReMomentServer/1.0')->get($url);
        } catch (\Throwable) {
            return false;
        }

        $info = $response->successful() ? @getimagesizefromstring($response->body()) : false;

        return $info !== false && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
    }

    private static function key(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($name));
    }
}
