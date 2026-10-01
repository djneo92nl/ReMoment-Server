<?php

namespace App\Domain\Library;

/**
 * Comparison keys for library names, so the same artist, album or track from
 * different sources (DLNA tags, Spotify, a speaker's now-playing) resolves to
 * one record. Keys are only compared, never shown. The rules are deliberately
 * small (see docs/architecture/library-identity.md):
 *
 * - all: Unicode compatibility form, diacritics dropped (æ, ø, ß… spelled
 *   out), lower case, "&" read
 *   as "and", apostrophes and periods dropped, other punctuation and
 *   whitespace collapsed to single spaces.
 * - artist: a leading "The " (or trailing ", The") and a "feat./ft./featuring"
 *   credit are ignored. "&", "and", "," and "x" between names are kept, so
 *   "Simon & Garfunkel" is not "Simon".
 * - album and track: trailing edition markers are ignored — a "(…)", "[…]" or
 *   " - …" suffix made only of edition words (remaster(ed), deluxe, expanded,
 *   anniversary, special/collector's/legacy edition, bonus tracks, years) —
 *   and, for tracks, a "(feat. …)" credit. Anything else stays, so "(Live)",
 *   "(Acoustic)", "(Radio Edit)", "(Mono)" or "(Japanese Edition)" are
 *   different records.
 *
 * Bump VERSION when a rule changes: `library:merge-duplicates` re-keys every
 * row and merges what newly matches.
 */
final class Normalizer
{
    public const VERSION = 1;

    /** Words that make a suffix an edition marker; at least one must be present. */
    private const EDITION_MARKERS = [
        'remaster', 'remastered', 'remasters', 'remastering', 'deluxe', 'expanded',
        'anniversary', 'special', 'collector', 'collectors', 'legacy', 'bonus',
    ];

    /** Words that may accompany a marker. */
    private const EDITION_FILLERS = [
        'edition', 'version', 'super', 'digital', 'digitally', 'the', 'and', 'track',
        'tracks', 'with', 'year', 're', 'master', 'mastered', 'reissue',
    ];

    public static function artist(?string $name): string
    {
        $name = self::clean($name);
        $name = self::stripFeaturing($name, parenthesisedOnly: false);
        $name = preg_replace('/^the\s+/iu', '', $name) ?? $name;
        $name = preg_replace('/,\s*the$/iu', '', $name) ?? $name;

        return self::key($name);
    }

    public static function album(?string $name): string
    {
        return self::key(self::stripEditions(self::clean($name)));
    }

    public static function track(?string $name): string
    {
        $name = self::stripFeaturing(self::clean($name), parenthesisedOnly: true);

        return self::key(self::stripEditions($name));
    }

    /** Trimmed, single-spaced, typographic quotes and dashes made plain. */
    private static function clean(?string $name): string
    {
        $name = strtr((string) $name, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{02BC}" => "'", '`' => "'",
            "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
            "\u{00A0}" => ' ',
        ]);

        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    /**
     * Drops "feat. X" credits: for artists also unbracketed ("A feat. B"), for
     * track titles only "(feat. B)" / "[feat. B]" / " - feat. B" so a title
     * that merely contains the word stays whole.
     */
    private static function stripFeaturing(string $name, bool $parenthesisedOnly): string
    {
        $credit = '(?:feat\.?|ft\.|featuring)\s+';

        $name = preg_replace('/\s*[\(\[]\s*'.$credit.'[^\)\]]*[\)\]]/iu', '', $name) ?? $name;
        $name = preg_replace('/\s+-\s+'.$credit.'.*$/iu', '', $name) ?? $name;

        if (!$parenthesisedOnly) {
            $name = preg_replace('/\s+'.$credit.'.*$/iu', '', $name) ?? $name;
        }

        return trim($name);
    }

    /** Repeatedly drops a trailing "(…)", "[…]" or " - …" that is only an edition marker. */
    private static function stripEditions(string $name): string
    {
        do {
            $before = $name;

            if (preg_match('/^(.+?)\s*[\(\[]([^\(\)\[\]]*)[\)\]]$/u', $name, $m) && self::isEditionMarker($m[2])) {
                $name = trim($m[1]);
            } elseif (preg_match('/^(.+?)\s+-\s+([^-]+)$/u', $name, $m) && self::isEditionMarker($m[2])) {
                $name = trim($m[1]);
            }
        } while ($name !== $before && $name !== '');

        return $name === '' ? $before : $name;
    }

    public static function isEditionMarker(string $suffix): bool
    {
        $words = preg_split('/\s+/u', self::key($suffix), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hasMarker = false;

        foreach ($words as $word) {
            if (in_array($word, self::EDITION_MARKERS, true)) {
                $hasMarker = true;
            } elseif (!in_array($word, self::EDITION_FILLERS, true)
                && !preg_match('/^(\d{4}|\d{1,3}(st|nd|rd|th))$/', $word)) {
                return false;
            }
        }

        return $hasMarker;
    }

    /** The comparison form; never empty for a non-empty name (a name of only punctuation keys as itself). */
    private static function key(string $name): string
    {
        $raw = mb_strtolower(trim($name));

        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_KD) ?: $name;
        }

        $name = preg_replace('/\p{Mn}+/u', '', $name) ?? $name;

        if (class_exists(\Normalizer::class)) {
            // Recompose what is left (e.g. Hangul syllables), so keys stay as short as names.
            $name = \Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name;
        }
        $name = mb_strtolower($name);
        // Latin letters without a decomposition, as they are commonly typed without them.
        $name = strtr($name, ['æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'ß' => 'ss', 'ł' => 'l', 'đ' => 'd', 'ð' => 'd', 'þ' => 'th', 'ı' => 'i']);
        $name = str_replace('&', ' and ', $name);
        $name = preg_replace("/['.]/u", '', $name) ?? $name;
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name;
        $name = trim($name);

        // name_key is a 255-character column, like name.
        return mb_substr($name !== '' ? $name : $raw, 0, 255);
    }
}
