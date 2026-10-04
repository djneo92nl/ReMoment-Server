<?php

namespace App\Integrations\Sonos;

use App\Domain\Device\AvailableSource;

/**
 * The physical inputs a Sonos speaker can switch to, by model. Soundbars
 * (Ray: optical, Beam/Arc: HDMI eARC) all present TV audio as one `tv` input;
 * line-in speakers (Five, Port, Amp, ...) have `line_in`. Switching means
 * pointing the transport at a special stream URI of the speaker itself.
 */
final class SonosInputs
{
    public const TV = 'tv';

    public const LINE_IN = 'line_in';

    /** @return string[] input ids the model has */
    public static function forModel(?string $model): array
    {
        $model = strtolower((string) $model);
        $inputs = [];

        if (preg_match('/\b(ray|beam|arc|playbar|playbase|amp)\b/', $model)) {
            $inputs[] = self::TV;
        }

        if (preg_match('/\b(five|play:5|port|connect|amp)\b/', $model)) {
            $inputs[] = self::LINE_IN;
        }

        return $inputs;
    }

    public static function uri(string $input, string $uuid): ?string
    {
        return match ($input) {
            self::TV => "x-sonos-htastream:{$uuid}:spdif",
            self::LINE_IN => "x-rincon-stream:{$uuid}",
            default => null,
        };
    }

    public static function idForUri(?string $uri): ?string
    {
        return match (true) {
            $uri === null => null,
            str_starts_with($uri, 'x-sonos-htastream:') => self::TV,
            str_starts_with($uri, 'x-rincon-stream:') => self::LINE_IN,
            default => null,
        };
    }

    public static function source(string $input, bool $inUse): AvailableSource
    {
        return $input === self::TV
            ? new AvailableSource(self::TV, 'TV', 'TV', 'video', $inUse, false, null, null)
            : new AvailableSource(self::LINE_IN, 'Line-in', 'LINE_IN', 'music', $inUse, false, null, null);
    }
}
