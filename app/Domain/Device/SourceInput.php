<?php

namespace App\Domain\Device;

/** The physical connection of a TV source as a label: "HDMI 2", "AV". Digital sources have none. */
final class SourceInput
{
    public static function label(?string $sourceType, string|int|null $connector = null): ?string
    {
        $connector = $connector === null || $connector === '' ? null : (string) $connector;

        return match (strtoupper($sourceType ?? '')) {
            'HDMI' => $connector !== null ? 'HDMI '.$connector : 'HDMI',
            'AV IN', 'AV' => 'AV',
            default => null,
        };
    }
}
