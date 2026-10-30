<?php

namespace App\Domain\Catalogue;

use App\Domain\Library\Normalizer;

/**
 * A looser album comparison key for the catalogue only: a library album named like a release ("One By One Special Norwegian
 * Edition (82876 512002)", "Five Songs And A Cover US Best Buy Exclusive EP (BMK 96143)") and the MusicBrainz release-group
 * ("One By One") get the same key. It never replaces Normalizer::album and is never stored on a library row: `name_key` drives
 * library identity and library:merge-duplicates, and must keep telling pressings apart.
 *
 * Works on the raw title (the brackets are gone once Normalizer has made a key), then lets Normalizer::album make the key, so
 * the usual normalisation is not duplicated. Strips, repeatedly from the end: a bracketed catalogue number, a bracket or
 * " - " group made only of edition words, and a bare trailing run of edition words that holds an anchor (edition, a region, a
 * retailer, EP …). Words that make another work (live, acoustic, remix, unplugged, demo, instrumental, mono, numbers) are
 * never on the lists, so `Live at Reading` and `Greatest Hits Vol 2` keep their key. A title that is nothing but edition
 * words falls back to Normalizer::album. Word lists: config/availability.php `edition_matching`.
 */
final class EditionKey
{
    public static function of(?string $title): string
    {
        $original = (string) $title;
        $s = self::prepare($original);
        $lists = self::lists();

        do {
            $before = $s;
            $s = self::stripTrailingGroup($s, $lists);
            $s = self::stripTrailingDash($s, $lists);
            $s = self::stripTrailingRun($s, $lists);
        } while ($s !== $before && $s !== '');

        // Nothing left but edition words ("Deluxe Edition" → "deluxe"): the title is not a pressing of anything, keep it whole.
        $onlyWords = array_filter(self::tokens($s), fn ($t) => !self::isMarkerToken($t, $lists) && !isset($lists['fillers'][$t]));
        $key = $s === '' || $onlyWords === [] ? '' : Normalizer::album($s);

        return $key !== '' ? $key : Normalizer::album($original);
    }

    /** Whether the title carries anything this key would strip (i.e. may differ from Normalizer::album). */
    public static function differs(?string $title): bool
    {
        return self::of($title) !== Normalizer::album($title);
    }

    private static function prepare(string $title): string
    {
        $s = mb_strtolower(trim($title));
        $s = strtr($s, ["\u{2018}" => "'", "\u{2019}" => "'", "\u{02BC}" => "'", '`' => "'", "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ']);
        $s = str_replace("'", '', $s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        foreach (self::lists()['phrases'] as $phrase => $token) {
            $s = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u', $token, $s) ?? $s;
        }

        // "cd 1", "disc 2" → one token
        return preg_replace('/(?<![\p{L}\p{N}])(cd|disc|disk)\s*(\d+)(?![\p{L}\p{N}])/u', '$1$2', $s) ?? $s;
    }

    /** @return array{markers: array<string,true>, fillers: array<string,true>, anchors: array<string,true>, regions: array<string,true>, phrases: array<string,string>} */
    private static function lists(): array
    {
        $c = config('availability.edition_matching', []);
        $markers = array_merge($c['regions'] ?? [], $c['editions'] ?? [], $c['retailers'] ?? [], $c['formats'] ?? []);
        $phrases = [];
        foreach ($markers as $m) {
            if (str_contains($m, '_')) {
                $phrases[str_replace('_', ' ', $m)] = $m;
            }
        }

        return [
            'markers' => array_fill_keys($markers, true),
            'fillers' => array_fill_keys($c['fillers'] ?? [], true),
            'anchors' => array_fill_keys($c['anchors'] ?? [], true),
            'regions' => array_fill_keys($c['regions'] ?? [], true),
            'phrases' => $phrases,
        ];
    }

    /** Disc markers, years after a marker, and the lists. */
    private static function isMarkerToken(string $t, array $lists): bool
    {
        return isset($lists['markers'][$t]) || preg_match('/^(cd|disc|disk)\d+$/', $t) === 1;
    }

    /** @return list<string> */
    private static function tokens(string $s): array
    {
        return preg_split('/[^\p{L}\p{N}_]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Every word is a marker, a filler or a year, and at least one is a marker. */
    private static function onlyEditionWords(string $content, array $lists): bool
    {
        $tokens = self::tokens($content);
        $marker = false;
        foreach ($tokens as $t) {
            if (self::isMarkerToken($t, $lists)) {
                $marker = true;
            } elseif (!isset($lists['fillers'][$t]) && !preg_match('/^(19|20)\d{2}$|^\d{1,3}(st|nd|rd|th)$/', $t)) {
                return false;
            }
        }

        return $marker;
    }

    /**
     * A catalogue number: letters, digits, spaces and . - / only, at least two digits, no word of letters that reads as a
     * word (an alphabetic token must be 1-6 letters, as in "BMK 96143" or "RR 12345"; "Part 2" and "Vol 12" are not).
     */
    private static function isCatalogueNumber(string $content): bool
    {
        $content = trim($content);
        if ($content === '' || !preg_match('/^[a-z0-9 .\-\/]+$/', $content) || preg_match_all('/\d/', $content) < 2) {
            return false;
        }

        foreach (preg_split('/[ .\-\/]+/', $content, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (preg_match('/^[a-z]+$/', $token) && (strlen($token) > 6 || in_array($token, ['part', 'vol', 'volume', 'disc', 'cd', 'no', 'pt'], true))) {
                return false;
            }
        }

        // A bare number like (2011) or (1984) is a year, not a pressing: only mixed or spaced numbers count.
        return !preg_match('/^\d{4}$/', $content);
    }

    private static function stripTrailingGroup(string $s, array $lists): string
    {
        if (preg_match('/^(.*?)\s*[\(\[]([^()\[\]]*)[\)\]]$/u', $s, $m) && trim($m[1]) !== ''
            && (self::isCatalogueNumber($m[2]) || self::onlyEditionWords($m[2], $lists))) {
            return trim($m[1]);
        }

        return $s;
    }

    private static function stripTrailingDash(string $s, array $lists): string
    {
        if (preg_match('/^(.+?)\s+-\s+([^-]+)$/u', $s, $m) && trim($m[1]) !== '' && self::onlyEditionWords($m[2], $lists)) {
            return trim($m[1]);
        }

        return $s;
    }

    private static function isAnchor(string $t, array $lists): bool
    {
        return isset($lists['anchors'][$t]) || isset($lists['regions'][$t]) || preg_match('/^(cd|disc|disk)\d+$/', $t) === 1;
    }

    private static function word(string $w): string
    {
        return preg_replace('/[^\p{L}\p{N}_]+/u', '', $w) ?? '';
    }

    /** A bare run of marker words at the end, longest first, when it starts with a marker, holds an anchor and leaves at least one word. */
    private static function stripTrailingRun(string $s, array $lists): string
    {
        $words = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($words);
        $start = $n;

        for ($i = $n - 1; $i >= 1; $i--) { // never index 0: at least one word stays
            $t = self::word($words[$i]);
            if ($t === '' || !(self::isMarkerToken($t, $lists) || isset($lists['fillers'][$t]) || preg_match('/^(19|20)\d{2}$/', $t))) {
                break;
            }
            $start = $i;
        }

        // The run starts with a marker, never a leading filler or year.
        while ($start < $n && !self::isMarkerToken(self::word($words[$start]), $lists)) {
            $start++;
        }

        for ($i = $start; $i < $n; $i++) {
            if (self::isAnchor(self::word($words[$i]), $lists)) {
                return implode(' ', array_slice($words, 0, $start));
            }
        }

        return $s;
    }
}
