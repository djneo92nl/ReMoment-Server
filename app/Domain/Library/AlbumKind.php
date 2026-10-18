<?php

namespace App\Domain\Library;

use App\Models\Media\Album;

/**
 * Which shelf of an artist's discography an album belongs on. Libraries hold every pressing of a release as its own
 * album ("Foo Fighters EU (RCA 82876 55496 2)", "Everlong UK CD1 (7243 …)"), and MusicBrainz' release type is often missing
 * or says "album" for a reissue, so this reads the name first and the stored release type and track count second.
 */
final class AlbumKind
{
    public const ALBUM = 'album';

    public const SINGLE = 'single';

    public const LIVE = 'live';

    public const COMPILATION = 'compilation';

    public const EDITION = 'edition';

    /** Kind => label, in the order the page lists them. */
    public const LABELS = [
        self::ALBUM => 'Albums',
        self::SINGLE => 'Singles & EPs',
        self::LIVE => 'Live',
        self::COMPILATION => 'Compilations',
        self::EDITION => 'Editions & pressings',
    ];

    public static function of(Album $album, int $trackCount): string
    {
        $name = $album->name;
        $type = strtolower((string) $album->metaValue('release_type'));

        if ($type === 'live' || preg_match('/\blive\b|\bunplugged\b|\bin concert\b/i', $name)) {
            return self::LIVE;
        }

        // A single or EP, also as a numbered disc or a pressing of one.
        if (in_array($type, ['single', 'ep'], true)
            || preg_match('/\b(?:single|CDS|ECD|maxi|CD ?[1-4]|EP)\b|\bCD ?(?:single|maxi)\b/', $name)
            || preg_match('/\bsingle\b/i', $name)) {
            return self::SINGLE;
        }

        // A "Best Of" name on a one-to-four track release is a song title ("Best Of You"), not a compilation.
        if ($type === 'compilation' || ($trackCount === 0 || $trackCount > 4) && preg_match('/greatest hits|\bbest of\b|\bcollection\b|\banthology\b|\bcompilation\b|\bthe essential\b/i', $name)) {
            return self::COMPILATION;
        }

        if (self::isEdition($name)) {
            return self::EDITION;
        }

        return $trackCount > 0 && $trackCount <= 3 && $type !== 'album' ? self::SINGLE : self::ALBUM;
    }

    /** A special edition or a regional pressing: a marker in brackets, a catalogue number, or a bare country code. */
    private static function isEdition(string $name): bool
    {
        $words = 'japan(?:ese)?|australia(?:n)?|france|germany|europe(?:an)?|usa|promo|limited|special|deluxe|expanded|remaster(?:ed)?|anniversary|edition|bonus|commentary|exclusive|reissue|mono|stereo|import|box set|super deluxe|collector|tour edition';

        return (bool) preg_match('/\([^)]*\b(?:'.$words.'|EU|UK|US|OZ)\b[^)]*\)/i', $name)
            || preg_match('/\(\s*[A-Z]{0,6}[ -]?[A-Z0-9]*\d{3,}[^)]*\)/', $name)
            || preg_match('/(?:^|\s)(?:EU|UK|US|OZ|Japan|Japanese)(?:\s+\(|\s*$|\s+\w+\s+\()/', $name)
            || preg_match('/\b(?:'.$words.')\b/i', $name) && preg_match('/\b(?:edition|remaster(?:ed)?|deluxe|expanded|anniversary)\b/i', $name);
    }
}
