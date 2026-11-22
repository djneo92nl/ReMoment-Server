<?php

namespace App\Domain\Concerts;

use App\Models\Setting;

/**
 * The two settings of the concerts feature (roadmap 12.6): the opt-in, and the place the user wants to hear about.
 * Artist ids go to MusicBrainz (the same service the enrichment already asks), so it is off until someone switches it on.
 */
final class ConcertSettings
{
    public const ENABLED = 'concerts_enabled';

    public const LOCATION = 'concerts_location';

    public static function enabled(): bool
    {
        return Setting::get(self::ENABLED) === '1';
    }

    public static function setEnabled(bool $on): void
    {
        Setting::set(self::ENABLED, $on ? '1' : '0');
    }

    /** The location text as typed (`Netherlands, Amsterdam`), or null for everywhere. */
    public static function location(): ?string
    {
        $text = trim((string) Setting::get(self::LOCATION, ''));

        return $text === '' ? null : $text;
    }

    public static function setLocation(?string $text): void
    {
        $text = $text === null ? '' : trim(preg_replace('/\s+/u', ' ', $text));
        $text === '' ? Setting::forget(self::LOCATION) : Setting::set(self::LOCATION, mb_substr($text, 0, 200));
    }

    /**
     * The place terms: a comma list, any of which may match (an event in any named city or country passes).
     *
     * @return list<string>
     */
    public static function terms(?string $location): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($t) => mb_strtolower(trim($t)), explode(',', (string) $location)), fn ($t) => $t !== '')));
    }
}
