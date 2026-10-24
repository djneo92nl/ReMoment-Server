<?php

namespace App\Domain\Radio;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The free radio-browser.info directory: search, browse (trending, most voted, by genre or country) and click counting.
 * Results carry a station's name, stream URL, logo, genre and codec, so adding one is a click instead of copying a URL.
 * The directory runs on several servers: they are looked up in DNS (all.api.radio-browser.info, as its operators ask),
 * tried in random order, and a fixed list is the fallback.
 */
class RadioBrowser
{
    private const FALLBACK_SERVERS = [
        'https://de1.api.radio-browser.info',
        'https://nl1.api.radio-browser.info',
        'https://at1.api.radio-browser.info',
    ];

    public const MODES = ['trending' => 'clicktrend', 'votes' => 'votes', 'popular' => 'clickcount'];

    /** @return list<array<string, mixed>> */
    public function search(string $name, int $limit = 20): array
    {
        $name = trim($name);

        if (mb_strlen($name) < 2) {
            return [];
        }

        return $this->stations('stations/search', ['name' => $name, 'order' => 'clickcount'], $limit);
    }

    /** What is hot: `trending` (clicks lately), `votes` or `popular` (clicks ever); optionally within a genre tag or a country code. @return list<array<string, mixed>> */
    public function browse(string $mode = 'trending', ?string $tag = null, ?string $country = null, int $limit = 20): array
    {
        $params = ['order' => self::MODES[$mode] ?? 'clicktrend'];

        if ($tag !== null && $tag !== '') {
            $params['tag'] = $tag;
            $params['tagExact'] = 'true';
        }

        if ($country !== null && $country !== '') {
            $params['countrycode'] = strtoupper($country);
        }

        return $this->stations('stations/search', $params, $limit);
    }

    /** The most used genre tags, for filter chips. @return list<string> */
    public function tags(int $limit = 15): array
    {
        return Cache::remember("radio-browser:tags:{$limit}", 86400, function () use ($limit) {
            $rows = $this->get('tags', ['order' => 'stationcount', 'reverse' => 'true', 'limit' => $limit * 3, 'hidebroken' => 'true']) ?? [];

            return collect($rows)->pluck('name')->filter(fn ($t) => preg_match('/^[\p{L}\d &\-]{3,24}$/u', (string) $t))->take($limit)->values()->all();
        });
    }

    /** One station by its directory uuid (its stream URL may have moved). @return array<string, mixed>|null */
    public function byUuid(string $stationUuid): ?array
    {
        $rows = $this->get('stations/byuuid/'.rawurlencode($stationUuid), []);

        return $rows === null ? null : ($this->map($rows)[0] ?? null);
    }

    /** Count a play of the station so the directory can rank it (its operators ask for one call per listen). */
    public function click(string $stationUuid): void
    {
        $this->get('url/'.rawurlencode($stationUuid), []);
    }

    /** @param array<string, mixed> $params @return list<array<string, mixed>> */
    private function stations(string $path, array $params, int $limit): array
    {
        $rows = $this->get($path, $params + [
            'limit' => max(1, min($limit, 50)),
            'hidebroken' => 'true',
            'reverse' => 'true',
        ]);

        return $rows === null ? [] : $this->map($rows);
    }

    /** @param array<string, mixed> $query @return array<int, mixed>|null the decoded JSON, null when no server answered */
    private function get(string $path, array $query): ?array
    {
        foreach ($this->servers() as $server) {
            try {
                $response = Http::timeout(6)
                    ->withUserAgent(config('metadata.user_agent'))
                    ->acceptJson()
                    ->get("{$server}/json/{$path}", $query);

                if ($response->successful()) {
                    return (array) $response->json();
                }
            } catch (\Throwable $e) {
                Log::debug("radio-browser {$server}: ".$e->getMessage());
            }
        }

        return null;
    }

    /** @return list<string> the servers in the order to try */
    private function servers(): array
    {
        $servers = Cache::remember('radio-browser:servers', 86400, fn () => $this->lookupServers());
        $servers = array_values(array_unique([...$servers, ...self::FALLBACK_SERVERS]));
        $live = array_slice($servers, 0, max(0, count($servers) - count(self::FALLBACK_SERVERS)));
        $rest = array_diff($servers, $live);
        shuffle($live);

        return [...$live, ...$rest];
    }

    /** The mirrors named by DNS; empty when DNS says nothing (and always in tests). @return list<string> */
    private function lookupServers(): array
    {
        if (app()->runningUnitTests()) {
            return [];
        }

        $names = [];

        foreach (@dns_get_record('all.api.radio-browser.info', DNS_A) ?: [] as $record) {
            $name = @gethostbyaddr($record['ip'] ?? '');

            if ($name && $name !== $record['ip'] && str_ends_with($name, '.radio-browser.info')) {
                $names[] = 'https://'.$name;
            }
        }

        return array_values(array_unique($names));
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
                'uuid' => trim((string) ($row['stationuuid'] ?? '')) ?: null,
                'homepage' => preg_match('#^https?://#i', $home = trim((string) ($row['homepage'] ?? ''))) ? $home : null,
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
