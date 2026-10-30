<?php

namespace App\Domain\Radio\Relay;

use App\Models\RadioStation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * What a station really serves, which its stored address does not say: where it ends up after redirects (an `http://`
 * address that redirects to `https://`, NTS), its codec and whether it is a playlist. Asked once per address and
 * remembered for six hours; a station that cannot be reached is described by its stored address alone.
 */
final class StationProbe
{
    private const TTL_HOURS = 6;

    /**
     * @return array{url: string, final_url: string, scheme: string, ip_host: bool, codec: string, playlist: bool}
     */
    public static function facts(RadioStation $station): array
    {
        $url = (string) $station->streamUrl();

        return Cache::remember('radio:relay:probe:'.$station->id.':'.md5($url), now()->addHours(self::TTL_HOURS), fn () => self::probe($url));
    }

    /** @return array{url: string, final_url: string, scheme: string, ip_host: bool, codec: string, playlist: bool} */
    public static function probe(string $url): array
    {
        $final = $url;
        $contentType = '';

        try {
            // A stream never ends: ask for its headers and drop the connection.
            $response = Http::timeout(6)
                ->withOptions(['stream' => true, 'allow_redirects' => ['max' => 5, 'track_redirects' => true]])
                ->withHeaders(['Icy-MetaData' => '0', 'User-Agent' => (string) config('radio_relay.user_agent')])
                ->get($url);

            $final = (string) ($response->effectiveUri() ?? $url);
            $contentType = (string) $response->header('Content-Type');
            $response->toPsrResponse()->getBody()->close();
        } catch (\Throwable) {
            // Unreachable now: describe it by its stored address.
        }

        return self::describe($url, $final, $contentType);
    }

    /** @return array{url: string, final_url: string, scheme: string, ip_host: bool, codec: string, playlist: bool} */
    public static function describe(string $url, string $finalUrl, string $contentType): array
    {
        $codec = self::codec($contentType, $finalUrl);

        return [
            'url' => $url,
            'final_url' => $finalUrl,
            'scheme' => strtolower((string) parse_url($finalUrl, PHP_URL_SCHEME)),
            'ip_host' => filter_var(parse_url($url, PHP_URL_HOST), FILTER_VALIDATE_IP) !== false,
            'codec' => $codec,
            'playlist' => in_array($codec, ['hls', 'playlist'], true),
        ];
    }

    /** `mp3`, `aac`, `ogg`, `hls` (a segmented stream), `playlist` (a `.pls`/`.m3u` that names the stream) or `unknown`. */
    public static function codec(string $contentType, string $url): string
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match (true) {
            in_array($type, ['application/vnd.apple.mpegurl', 'application/x-mpegurl'], true), $extension === 'm3u8' => 'hls',
            in_array($type, ['audio/x-scpls', 'audio/x-mpegurl', 'audio/mpegurl'], true), in_array($extension, ['pls', 'm3u'], true) => 'playlist',
            in_array($type, ['audio/mpeg', 'audio/mp3'], true), $extension === 'mp3' => 'mp3',
            in_array($type, ['audio/aac', 'audio/aacp', 'audio/mp4', 'audio/x-aac'], true), in_array($extension, ['aac', 'm4a'], true) => 'aac',
            in_array($type, ['audio/ogg', 'application/ogg', 'audio/opus'], true), in_array($extension, ['ogg', 'oga', 'opus'], true) => 'ogg',
            default => 'unknown',
        };
    }
}
