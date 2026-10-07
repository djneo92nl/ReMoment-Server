<?php

namespace App\Domain\Device;

/** The Font Awesome icon of a source type (HDMI, line-in, DLNA, ...). */
final class SourceIcon
{
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
