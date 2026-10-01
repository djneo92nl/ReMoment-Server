<?php

namespace App\Domain\Library;

final class PlaybackResult
{
    public const VIA_DLNA = 'dlna';

    public const VIA_SPOTIFY = 'spotify';

    public function __construct(
        public readonly string $via,
        /** Tracks that could be played by that path. */
        public readonly int $playable,
        public readonly int $total,
    ) {}
}
