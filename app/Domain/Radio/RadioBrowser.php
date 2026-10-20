<?php

namespace App\Domain\Radio;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Search in the free radio-browser.info directory: a station's name, stream URL, logo, genre and codec, so adding one
 * is a click instead of copying a URL. The directory runs on several mirrors; the first that answers is used.
 */
class RadioBrowser
{
    private const MIRRORS = [
        'https://de1.api.radio-browser.info',
        'https://nl1.api.radio-browser.info',
        'https://at1.api.radio-browser.info',
    ];

    /** @return list<array{name: string, url: string, image_url: ?string, genre: ?string, codec: ?string, bitrate: ?int, country: ?string}> */
    public function search(string $name, int $limit = 20): array
    {
        $name = trim($name);

        if (mb_strlen($name) < 2) {
            return [];
        }

        foreach (self::MIRRORS as $mirror) {
            try {
                $response = Http::timeout(6)
                    ->withUserAgent(config('metadata.user_agent'))
                    ->acceptJson()
                    ->get($mirror.'/json/stations/search', [
                        'name' => $name,
                        'limit' => max(1, min($limit, 50)),
                        'hidebroken' => 'true',
                        'order' => 'clickcount',
                        'reverse' => 'true',
                    ]);

                if ($response->successful()) {
                    return $this->map((array) $response->json());
                }
            } catch (\Throwable $e) {
                Log::debug("radio-browser {$mirror}: ".$e->getMessage());
            }
        }

        return [];
    }

    /** @param array<int, mixed> $rows */
    private function map(array $rows): array
    {
        $stations = [];

        foreach ($rows as $row) {
            $url = trim((string) ($row['url_resolved'] ?? '')) ?: trim((string) ($row['url'] ?? ''));

            // A speaker can only open a plain HTTP(S) stream; playlists (m3u/pls) and other schemes are skipped.
            if (!preg_match('#^https?://#i', $url) || preg_match('#\.(m3u8?|pls|asx)(\?|$)#i', $url) || (int) ($row['hls'] ?? 0) === 1) {
                continue;
            }

            $image = trim((string) ($row['favicon'] ?? ''));

            $stations[] = [
                'name' => trim((string) ($row['name'] ?? '')) ?: $url,
                'url' => $url,
                'image_url' => preg_match('#^https?://#i', $image) ? $image : null,
                'genre' => $this->genre((string) ($row['tags'] ?? '')),
                'codec' => ($codec = strtoupper(trim((string) ($row['codec'] ?? '')))) !== '' ? $codec : null,
                'bitrate' => ($bitrate = (int) ($row['bitrate'] ?? 0)) > 0 ? $bitrate : null,
                'country' => trim((string) ($row['countrycode'] ?? '')) ?: null,
            ];
        }

        return $stations;
    }

    /** The first tag, as a genre ("pop,top 40,hits" → "Pop"). */
    private function genre(string $tags): ?string
    {
        $first = trim(explode(',', $tags)[0]);

        return $first === '' ? null : mb_convert_case($first, MB_CASE_TITLE, 'UTF-8');
    }
}
