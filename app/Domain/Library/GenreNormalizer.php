<?php

namespace App\Domain\Library;

use Illuminate\Support\Str;

/**
 * Turns the genre strings of every source (MusicBrainz, Spotify, DLNA tags, Last.fm) into one canonical set.
 *
 * Genres are identified by a key (lower case, ASCII, "&" as "and", letters and digits only), so "hip hop",
 * "Hip-Hop" and "hiphop" are one genre; the first display name seen wins, unless ALIASES names one.
 * Tags that aren't genres (nationalities, decades, "seen live", ...) are dropped. Bump VERSION when a
 * rule changes and run `library:sync-genres`.
 */
final class GenreNormalizer
{
    public const VERSION = 1;

    /** Keys whose spelling differs from the preferred genre's, with its display name. */
    private const ALIASES = [
        'rnb' => 'R&B',
        'rhythmandblues' => 'R&B',
        'rhythmnblues' => 'R&B',
        'dnb' => 'Drum & Bass',
        'drumnbass' => 'Drum & Bass',
        'drumandbass' => 'Drum & Bass',
        'rocknroll' => 'Rock & Roll',
        'rockandroll' => 'Rock & Roll',
        'edm' => 'EDM',
        'electronica' => 'Electronic',
        'electro' => 'Electro',
        'altrock' => 'Alternative Rock',
        'alternativeandpunk' => 'Alternative',
        'hiphoprap' => 'Hip-Hop',
        'rapandhiphop' => 'Hip-Hop',
        'singersongwriters' => 'Singer-Songwriter',
        'soundtracks' => 'Soundtrack',
        'ost' => 'Soundtrack',
        'filmscore' => 'Soundtrack',
    ];

    /** Words kept in capitals when a display name is built. */
    private const ACRONYMS = ['uk', 'us', 'edm', 'idm', 'dj', 'ebm', 'ost', 'nwobhm', 'rnb', 'dnb'];

    /** Keys of tags that describe something other than a genre. */
    private const NOISE = [
        'seenlive', 'favorites', 'favourites', 'favorite', 'favourite', 'albumsiown', 'underrated', 'spotify',
        'malevocalists', 'femalevocalists', 'malevocalist', 'femalevocalist', 'singer', 'band', 'duo', 'unknown',
        'american', 'british', 'english', 'german', 'french', 'dutch', 'canadian', 'australian', 'swedish',
        'norwegian', 'irish', 'scottish', 'welsh', 'japanese', 'korean', 'italian', 'spanish', 'brazilian',
    ];

    public static function key(string $genre): string
    {
        $ascii = Str::ascii(strtolower($genre));

        return preg_replace('/[^a-z0-9]+/', '', str_replace('&', 'and', $ascii));
    }

    /**
     * Canonical [key, name] pairs for raw genre strings, in order, without duplicates or non-genres.
     *
     * @param  iterable<mixed>  $raw
     * @return list<array{0: string, 1: string}>
     */
    public static function normalize(iterable $raw): array
    {
        $out = [];

        foreach ($raw as $genre) {
            if (!is_string($genre)) {
                continue;
            }

            $name = trim(preg_replace('/[\s_]+/', ' ', $genre));
            $key = self::key($name);

            if (strlen($key) < 2 || mb_strlen($name) > 40 || in_array($key, self::NOISE, true) || preg_match('/^\d{2,4}s$/', $key)) {
                continue;
            }

            $name = self::ALIASES[$key] ?? self::display($name);
            $key = self::key($name);

            $out[$key] ??= [$key, $name];
        }

        return array_values($out);
    }

    private static function display(string $name): string
    {
        $title = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);

        return preg_replace_callback(
            '/\b('.implode('|', self::ACRONYMS).')\b/i',
            fn (array $m) => strtoupper($m[1]),
            $title,
        );
    }
}
