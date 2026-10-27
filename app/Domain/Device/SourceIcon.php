<?php

namespace App\Domain\Device;

/** The Font Awesome icon of a source type (HDMI, line-in, DLNA, ...). */
final class SourceIcon
{
    /** Brand icons (Font Awesome has no Netflix, Jellyfin or Videoland): words of the name or package => full classes. */
    private const BRANDS = [
        'youtube' => 'fa-brands fa-youtube',
        'spotify' => 'fa-brands fa-spotify',
        'deezer' => 'fa-brands fa-deezer',
        'soundcloud' => 'fa-brands fa-soundcloud',
        'amazon' => 'fa-brands fa-amazon',
        'twitch' => 'fa-brands fa-twitch',
        'apple' => 'fa-brands fa-apple',
        'vending' => 'fa-brands fa-google-play',
        'play store' => 'fa-brands fa-google-play',
        'bluetooth' => 'fa-brands fa-bluetooth-b',
        'netflix' => 'fa-solid fa-clapperboard',
        'jellyfin' => 'fa-solid fa-clapperboard',
        'videoland' => 'fa-solid fa-clapperboard',
        'kijk' => 'fa-solid fa-clapperboard',
        'npo' => 'fa-solid fa-clapperboard',
        'games' => 'fa-solid fa-gamepad',
    ];

    /**
     * The full Font Awesome classes (`fa-brands fa-youtube`, `fa-solid fa-display`) of a source: a known service by
     * its name or id (an app's package), else the icon of its type; an unknown app is a play button.
     */
    public static function classes(?string $sourceType, ?string $name = null, ?string $sourceId = null): string
    {
        $haystack = strtolower($name.' '.$sourceId);

        foreach (self::BRANDS as $word => $classes) {
            if ($word !== '' && str_contains($haystack, $word)) {
                return $classes;
            }
        }

        if (strtoupper($sourceType ?? '') === 'APP') {
            return 'fa-solid fa-circle-play';
        }

        return 'fa-solid '.self::for($sourceType);
    }

    public static function for(?string $sourceType): string
    {
        return match (strtoupper($sourceType ?? '')) {
            'HDMI', 'TV' => 'fa-display',
            'TUNEIN', 'RADIO' => 'fa-tower-broadcast',
            'DLNA', 'UPNP' => 'fa-network-wired',
            'CD', 'DVD' => 'fa-compact-disc',
            'OPTICAL' => 'fa-circle',
            'BLUETOOTH' => 'fa-bluetooth',
            default => 'fa-plug', // line-in and anything unknown
        };
    }
}
