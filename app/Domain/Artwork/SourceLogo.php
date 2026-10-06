<?php

namespace App\Domain\Artwork;

use App\Domain\Media\NowPlaying;

/**
 * Generated stand-in artwork for playback without an image (line-in, TV,
 * a radio station without a logo, …): a neutral glyph on a dark square,
 * drawn by LogoRenderer and run through ProcessArtwork like any cover.
 *
 * Logos are addressed by a pseudo URL ("remoment:logo/v1/{key}"), so the
 * cache key and storage path (artwork/{md5(url)}/…) are the same on every
 * server and clients can cache them by hash like album covers. Bump VERSION
 * when a glyph changes, so clients don't keep showing the old one.
 */
final class SourceLogo
{
    public const VERSION = 1;

    private const URL_PREFIX = 'remoment:logo/';

    /**
     * Logo key => words that select it, matched against the source type,
     * name, connector and category (first match wins). Words of 5+ letters
     * also match inside run-together names ("LINEIN", "NetRadio").
     */
    private const RULES = [
        'spotify' => ['spotify'],
        'bluetooth' => ['bluetooth', 'a2dp'],
        'cast' => ['airplay', 'chromecast', 'googlecast', 'cast'],
        'radio' => ['radio', 'tunein', 'dab', 'fm'],
        'line_in' => ['linein', 'line', 'aux', 'analog', 'optical', 'spdif', 'toslink', 'amem'],
        'cd' => ['cd', 'dvd', 'bluray'],
        'tv' => ['tv', 'hdmi', 'video'],
    ];

    /** Used when nothing matches. */
    public const DEFAULT = 'music';

    public const KEYS = ['spotify', 'bluetooth', 'cast', 'radio', 'line_in', 'cd', 'tv', self::DEFAULT];

    public static function url(string $key): string
    {
        return self::URL_PREFIX.'v'.self::VERSION.'/'.$key;
    }

    /** The logo key of a logo pseudo URL, or null for a real image URL. */
    public static function keyFromUrl(string $url): ?string
    {
        if (!preg_match('#^'.preg_quote(self::URL_PREFIX, '#').'v\d+/([a-z_]+)$#', $url, $m)) {
            return null;
        }

        return in_array($m[1], self::KEYS, true) ? $m[1] : null;
    }

    public static function isLogoUrl(string $url): bool
    {
        return self::keyFromUrl($url) !== null;
    }

    /** The logo that best describes what is playing. */
    public static function keyFor(NowPlaying $nowPlaying): string
    {
        if ($nowPlaying->radio !== null || $nowPlaying->platform === 'radio') {
            return 'radio';
        }

        $source = $nowPlaying->source;

        return self::match([
            $source?->sourceType,
            $source?->name,
            $source?->connector,
            $nowPlaying->track?->source,
            $source?->category,
        ]);
    }

    /** @param array<int, string|null> $labels */
    public static function match(array $labels): string
    {
        return self::firstMatch($labels, self::RULES) ?? self::DEFAULT;
    }

    /**
     * The first key of $rules whose words appear in the first label that
     * matches any of them, or null. Shared with the source control profiles.
     *
     * @param  array<int, string|null>  $labels
     * @param  array<string, string[]>  $rules  key => words
     */
    public static function firstMatch(array $labels, array $rules): ?string
    {
        foreach ($labels as $label) {
            $words = preg_split('/[^a-z0-9]+/', strtolower((string) $label), -1, PREG_SPLIT_NO_EMPTY);
            if ($words === []) {
                continue;
            }
            $compact = implode('', $words);

            foreach ($rules as $key => $needles) {
                foreach ($needles as $needle) {
                    if (in_array($needle, $words, true) || (strlen($needle) >= 5 && str_contains($compact, $needle))) {
                        return $key;
                    }
                }
            }
        }

        return null;
    }
}
