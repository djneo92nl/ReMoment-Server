<?php

namespace App\Domain\Lastfm;

use App\Models\Setting;

/**
 * Streaming sources whose plays are left out of Last.fm scrobbling and now-playing,
 * for services that scrobble themselves (Spotify, with its own Last.fm link).
 */
class ScrobbleSources
{
    public const SETTING = 'lastfm_disabled_sources';

    /** Source key (as stored in `plays.source_type`, lowercase) => label. */
    public const STREAMING = [
        'spotify' => 'Spotify',
        'deezer' => 'Deezer',
        'tidal' => 'Tidal',
        'qobuz' => 'Qobuz',
    ];

    /** @return list<string> */
    public static function disabled(): array
    {
        $value = json_decode((string) Setting::get(self::SETTING, '[]'), true);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** @param  list<string>  $sources  unknown keys are dropped */
    public static function setDisabled(array $sources): void
    {
        Setting::set(self::SETTING, json_encode(array_values(array_intersect(array_keys(self::STREAMING), $sources))));
    }

    /** Whether plays from this source (a `plays.source_type` value) are sent to Last.fm. */
    public static function enabled(?string $sourceType): bool
    {
        return $sourceType === null || !in_array(strtolower($sourceType), self::disabled(), true);
    }
}
