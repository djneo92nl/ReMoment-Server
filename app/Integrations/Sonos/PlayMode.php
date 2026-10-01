<?php

namespace App\Integrations\Sonos;

use App\Domain\Device\RepeatMode;

/**
 * Sonos encodes shuffle and repeat together in one AVTransport PlayMode
 * string. duncan3dc/sonos only knows repeat on/off, so REPEAT_ONE and
 * SHUFFLE_REPEAT_ONE are mapped here.
 */
final class PlayMode
{
    private const MODES = [
        'NORMAL' => [false, RepeatMode::Off],
        'REPEAT_ALL' => [false, RepeatMode::All],
        'REPEAT_ONE' => [false, RepeatMode::One],
        'SHUFFLE_NOREPEAT' => [true, RepeatMode::Off],
        'SHUFFLE' => [true, RepeatMode::All],
        'SHUFFLE_REPEAT_ONE' => [true, RepeatMode::One],
    ];

    /** @return array{0: ?bool, 1: ?RepeatMode} shuffle and repeat; nulls for an unknown mode */
    public static function parse(string $playMode): array
    {
        return self::MODES[strtoupper(trim($playMode))] ?? [null, null];
    }

    public static function build(bool $shuffle, RepeatMode $repeat): string
    {
        foreach (self::MODES as $mode => [$modeShuffle, $modeRepeat]) {
            if ($modeShuffle === $shuffle && $modeRepeat === $repeat) {
                return $mode;
            }
        }

        return 'NORMAL';
    }
}
