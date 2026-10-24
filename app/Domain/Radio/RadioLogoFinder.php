<?php

namespace App\Domain\Radio;

use App\Integrations\Radio\Nts\NtsClient;
use App\Models\RadioStation;
use Illuminate\Support\Facades\Http;

/**
 * A logo for a station that has none (or one the artwork pipeline cannot read: SVG, ICO): the directory's favicon for the
 * station (by its uuid, else the search result with the same stream host), else an icon from its homepage
 * (apple-touch-icon, og:image, a PNG link rel icon). Returns a URL of a raster image or null.
 */
class RadioLogoFinder
{
    public function __construct(private readonly RadioBrowser $browser) {}

    /** True for an image URL the artwork pipeline (GD) can process. */
    public static function usable(?string $url): bool
    {
        return filled($url) && !preg_match('#\.(svg|ico)(\?|$)#i', $url);
    }

    /** Usable and not a page background, banner or slide (a directory's "favicon" is sometimes one). */
    private static function plausible(?string $url): bool
    {
        return self::usable($url) && !preg_match('#(^|[/_.-])(bg|background|banner|header|hero|slide)#i', basename((string) parse_url($url, PHP_URL_PATH)));
    }

    /** Wikimedia only serves its standard thumbnail widths: a 1024px URL is a 400, so ask for 500px. */
    public static function normalize(?string $url): ?string
    {
        return $url !== null && str_contains($url, 'upload.wikimedia.org') ? preg_replace('#/\d+px-#', '/500px-', $url) : $url;
    }

    public function find(RadioStation $station): ?string
    {
        if ($station->getMeta('nts_id') !== null) {
            return NtsClient::LOGO;
        }

        $candidates = $this->candidates($station);

        foreach ($candidates as $candidate) {
            if (self::plausible($candidate['image_url'])) {
                return $candidate['image_url'];
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate['homepage'] && ($icon = $this->homepageIcon($candidate['homepage']))) {
                return $icon;
            }
        }

        return null;
    }

    /** @return list<array{image_url: ?string, homepage: ?string}> the directory's records for this station, best first */
    private function candidates(RadioStation $station): array
    {
        $found = [];

        if ($uuid = $station->getMeta('radio_browser_uuid')) {
            $found[] = $this->browser->byUuid($uuid);
        }

        $host = parse_url((string) $station->streamUrl(), PHP_URL_HOST);

        foreach ($this->browser->search($station->name, 15) as $result) {
            // Same stream host only: a same-named station elsewhere (there are several "Sky Radio") is another station.
            if ($host && parse_url($result['url'], PHP_URL_HOST) === $host) {
                $found[] = $result;
            }
        }

        return array_values(array_filter($found, fn ($r) => is_array($r)));
    }

    private function homepageIcon(string $homepage): ?string
    {
        try {
            $response = Http::timeout(8)->withUserAgent(config('metadata.user_agent'))->get($homepage);

            if (!$response->successful()) {
                return null;
            }

            $html = substr($response->body(), 0, 200000);
            $base = $response->effectiveUri() ? (string) $response->effectiveUri() : $homepage;

            foreach ([
                '/<link[^>]+rel=["\']apple-touch-icon[^"\']*["\'][^>]*href=["\']([^"\']+)["\']/i',
                '/<link[^>]+href=["\']([^"\']+)["\'][^>]*rel=["\']apple-touch-icon[^"\']*["\']/i',
                '/<meta[^>]+property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']/i',
                '/<link[^>]+rel=["\'](?:shortcut )?icon["\'][^>]*href=["\']([^"\']+\.png[^"\']*)["\']/i',
            ] as $pattern) {
                if (preg_match($pattern, $html, $m) && ($url = $this->absolute(html_entity_decode($m[1]), $base)) && self::plausible($url)) {
                    return $url;
                }
            }
        } catch (\Throwable) {
            // A homepage that does not answer has no icon for us.
        }

        return null;
    }

    private function absolute(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $parts = parse_url($base);

        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (str_starts_with($href, '//')) {
            return $parts['scheme'].':'.$href;
        }

        if (str_starts_with($href, '/')) {
            return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$href;
        }

        return rtrim(dirname($parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '/').'x'), '/').'/'.$href;
    }
}
